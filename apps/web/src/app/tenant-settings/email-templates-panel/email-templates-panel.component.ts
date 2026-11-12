import {
  Component,
  ElementRef,
  EventEmitter,
  Input,
  OnDestroy,
  OnInit,
  Output,
  ViewChild,
  inject,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DomSanitizer, type SafeHtml } from '@angular/platform-browser';
import { Subject, of } from 'rxjs';
import { catchError, debounceTime, map, switchMap, takeUntil } from 'rxjs/operators';
import { RichHtmlEditorComponent } from '../../shared/rich-html-editor/rich-html-editor.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { ApiError } from '../../core/api';
import {
  EmailTemplatesService,
  type EmailBranding,
  type EmailTemplate,
} from '../../services/email-templates.service';

/** A heading in the list, and the kinds of message under it. */
interface TemplateGroup {
  key: string;
  icon: string;
  items: EmailTemplate[];
}

/** Where each kind of message sits on the screen, and its icon. */
const GROUPS: { key: string; icon: string; types: string[] }[] = [
  { key: 'account', icon: 'bi-person-badge', types: ['welcome', 'invitation', 'password_reset'] },
  {
    key: 'learning',
    icon: 'bi-journal-bookmark',
    types: [
      'enrollment_assigned', 'enrollment_confirmed', 'enrollment_due_soon',
      'enrollment_overdue', 'course_completed',
    ],
  },
  { key: 'assessments', icon: 'bi-award', types: ['assessment_graded', 'certificate_issued', 'certificate_revoked'] },
  {
    key: 'sessions',
    icon: 'bi-camera-video',
    types: ['session_scheduled', 'session_reminder', 'session_rescheduled', 'session_cancelled'],
  },
  { key: 'community', icon: 'bi-chat-dots', types: ['forum_reply', 'forum_mention'] },
  { key: 'announcements', icon: 'bi-megaphone', types: ['announcement'] },
];

const TYPE_ICONS: Record<string, string> = {
  welcome: 'bi-emoji-smile',
  invitation: 'bi-envelope-open',
  password_reset: 'bi-key',
  enrollment_assigned: 'bi-journal-plus',
  enrollment_confirmed: 'bi-journal-check',
  enrollment_due_soon: 'bi-hourglass-split',
  enrollment_overdue: 'bi-exclamation-circle',
  course_completed: 'bi-flag',
  assessment_graded: 'bi-clipboard-check',
  certificate_issued: 'bi-award',
  certificate_revoked: 'bi-x-octagon',
  session_scheduled: 'bi-calendar-plus',
  session_reminder: 'bi-alarm',
  session_rescheduled: 'bi-calendar-event',
  session_cancelled: 'bi-calendar-x',
  forum_reply: 'bi-reply',
  forum_mention: 'bi-at',
  announcement: 'bi-megaphone',
};

/** Filled per organisation when the email goes out: its look, not its words. */
const BRAND_PLACEHOLDERS = ['brand_color', 'brand_text_color', 'logo_url', 'organisation', 'site_url'];

/**
 * Settings > Email templates.
 *
 * Every kind of message the platform emails, grouped the way an administrator
 * thinks about them, each with a plain "sent when…" line. The editor shows a
 * live preview of the real, branded email — the server renders it with the
 * organisation's logo, colour and footer — in a sandboxed frame, so what you
 * see is what lands in the inbox, inline styles and all.
 *
 * The layout itself is not editable here: it comes from the Branding settings
 * and is applied to every email, custom or default.
 */
@Component({
  selector: 'app-email-templates-panel',
  standalone: true,
  imports: [CommonModule, FormsModule, RichHtmlEditorComponent, SkeletonComponent, TranslatePipe],
  templateUrl: './email-templates-panel.component.html',
  styleUrls: ['./email-templates-panel.component.scss'],
})
export class EmailTemplatesPanelComponent implements OnInit, OnDestroy {
  private readonly service = inject(EmailTemplatesService);
  private readonly i18n = inject(I18nService);
  private readonly dialog = inject(ConfirmDialogService);
  private readonly sanitizer = inject(DomSanitizer);
  private readonly destroy$ = new Subject<void>();
  private readonly previewRequests$ = new Subject<void>();

  /** Fallback branding from the settings form, when the API does not report it. */
  @Input() orgName = '';
  @Input() brandColor = '';
  @Input() logoUrl = '';
  /** Whether a Branding tab exists to send the administrator to. */
  @Input() canEditBranding = true;
  @Output() openBranding = new EventEmitter<void>();

  @ViewChild('bodyEditor') bodyEditor?: RichHtmlEditorComponent;
  @ViewChild('subjectInput') subjectInput?: ElementRef<HTMLInputElement>;

  // --- the list ------------------------------------------------------------

  state: 'loading' | 'ready' | 'error' = 'loading';
  loadError = '';
  templates: EmailTemplate[] = [];
  groups: TemplateGroup[] = [];
  placeholders: string[] = [];
  private apiBranding: EmailBranding | null = null;

  // --- the editor ----------------------------------------------------------

  editing: EmailTemplate | null = null;
  editorLoading = false;
  form = { subject: '', body: '', enabled: true };
  private pristine = { subject: '', body: '', enabled: true };
  private lastField: 'subject' | 'body' = 'body';

  saving = false;
  successMessage = '';
  errorMessage = '';

  // --- the preview ---------------------------------------------------------

  previewSubject = '';
  previewHtml: SafeHtml | null = null;
  previewLoading = false;
  previewFailed = false;
  previewWidth: 'desktop' | 'mobile' = 'desktop';

  ngOnInit(): void {
    this.previewRequests$
      .pipe(
        debounceTime(450),
        switchMap(() => {
          const type = this.editing?.type;

          if (!type) {
            return of(null);
          }

          this.previewLoading = true;

          return this.service
            .preview(type, { title: this.form.subject.trim(), body: this.bodyToSend() })
            .pipe(
              map((p) => ({ ok: true as const, ...p })),
              catchError(() => of({ ok: false as const, subject: '', html: '' })),
            );
        }),
        takeUntil(this.destroy$),
      )
      .subscribe((result) => {
        this.previewLoading = false;

        if (result === null) {
          return;
        }

        if (result.ok && result.html.trim() !== '') {
          this.previewFailed = false;
          this.showPreview(result.subject, result.html);
        } else {
          // The preview endpoint is unavailable: keep the editor usable with a
          // close local approximation rather than an empty frame.
          this.previewFailed = true;
          this.showPreview(this.localSubject(), this.localPreview());
        }
      });

    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  load(): void {
    this.state = 'loading';
    this.loadError = '';

    this.service
      .list()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (list) => {
          this.templates = list.templates ?? [];
          this.placeholders = list.placeholders ?? [];
          this.apiBranding = list.branding ?? null;
          this.groups = this.buildGroups(this.templates);
          this.state = 'ready';
        },
        error: (failure: ApiError) => {
          this.loadError = failure?.message ?? '';
          this.state = 'error';
        },
      });
  }

  // --- naming --------------------------------------------------------------

  /** A friendly name, translated; the API's label when we have no wording. */
  nameOf(t: EmailTemplate): string {
    return this.translated(`tenant.email_type.${t.type}`) ?? t.label;
  }

  /** One line on when it is sent. */
  descriptionOf(t: EmailTemplate): string {
    return this.translated(`tenant.email_type_desc.${t.type}`) ?? t.description ?? t.default?.title ?? '';
  }

  iconOf(t: EmailTemplate): string {
    return TYPE_ICONS[t.type] ?? 'bi-envelope';
  }

  /**
   * Whether the organisation has its own wording saved, paused or not.
   * `usesDefault` says what is sent; this says whether there is anything to
   * reset (a paused custom template sends the default but still exists).
   */
  isCustomised(t: EmailTemplate): boolean {
    return t.customised ?? !!(t.subject || t.body);
  }

  get customisedCount(): number {
    return this.templates.filter((t) => this.isCustomised(t)).length;
  }

  // --- branding ------------------------------------------------------------

  get brandName(): string {
    return (this.apiBranding?.organisation || this.orgName || '').trim();
  }

  get brandPrimary(): string {
    const colour = (this.apiBranding?.primaryColor || this.brandColor || '').trim();

    return /^#[0-9a-f]{3,8}$/i.test(colour) ? colour : '#1f4e79';
  }

  /** The raw logo address, whatever it is. */
  get brandLogoRaw(): string {
    return (this.apiBranding?.logoUrl || this.logoUrl || '').trim();
  }

  /**
   * The logo the emails will show. The server decides when it reports
   * branding (it walks the email logo -> logo -> profile logo chain and drops
   * relative, http and signed/expiring links); otherwise a local guess.
   */
  get brandLogo(): string | null {
    if (this.apiBranding) {
      return this.apiBranding.logoUrl?.trim() || null;
    }

    const url = this.brandLogoRaw;

    return /^https:\/\//i.test(url) && !/[?&](ticket|token|signature|x-amz-signature)=/i.test(url) ? url : null;
  }

  get logoProblem(): 'missing' | 'not_public' | null {
    const b = this.apiBranding;

    if (b && b.logoConfigured !== undefined) {
      return !b.logoConfigured ? 'missing' : b.logoIsPublic ? null : 'not_public';
    }

    if (this.brandLogoRaw === '') {
      return 'missing';
    }

    return this.brandLogo === null ? 'not_public' : null;
  }

  /** Uploading a logo to a public address, from the strip's warning. */
  uploadingLogo = false;

  uploadLogo(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    input.value = '';

    if (!file || this.uploadingLogo) {
      return;
    }

    this.uploadingLogo = true;
    this.clearMessages();

    this.service
      .uploadLogo('email-logo', file)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.uploadingLogo = false;
          this.successMessage = this.i18n.t('tenant.saved');
          this.load();
        },
        error: (failure: ApiError) => {
          this.uploadingLogo = false;
          this.fail(failure);
        },
      });
  }

  goToBranding(): void {
    this.openBranding.emit();
  }

  // --- placeholders ----------------------------------------------------------

  get contentPlaceholders(): string[] {
    return this.placeholders.filter((p) => !BRAND_PLACEHOLDERS.includes(p));
  }

  get brandPlaceholders(): string[] {
    return this.placeholders.filter((p) => BRAND_PLACEHOLDERS.includes(p));
  }

  placeholderLabel(name: string): string {
    return this.translated(`tenant.placeholder.${name}`) ?? name;
  }

  token(name: string): string {
    return `{{${name}}}`;
  }

  focusField(field: 'subject' | 'body'): void {
    this.lastField = field;
  }

  /** Drops a placeholder at the caret of whichever field was used last. */
  insertPlaceholder(name: string): void {
    const text = this.token(name);
    const input = this.subjectInput?.nativeElement;

    if (this.lastField === 'subject' && input) {
      const start = input.selectionStart ?? this.form.subject.length;
      const end = input.selectionEnd ?? start;

      this.form.subject = this.form.subject.slice(0, start) + text + this.form.subject.slice(end);
      this.changed();

      // Put the caret back after what was inserted.
      setTimeout(() => {
        input.focus();
        input.setSelectionRange(start + text.length, start + text.length);
      });

      return;
    }

    if (this.bodyEditor) {
      this.bodyEditor.insert(text);
    } else {
      this.form.body = `${this.form.body}${text}`;
    }

    this.changed();
  }

  // --- editing ---------------------------------------------------------------

  get dirty(): boolean {
    return this.form.subject !== this.pristine.subject
      || this.form.body !== this.pristine.body
      || this.form.enabled !== this.pristine.enabled;
  }

  open(t: EmailTemplate): void {
    this.clearMessages();
    this.editing = t;
    this.editorLoading = true;
    this.previewHtml = null;
    this.previewFailed = false;
    this.lastField = 'body';

    this.service
      .show(t.type)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (template) => {
          this.editing = { ...t, ...template };
          this.fillForm(this.editing);
          this.editorLoading = false;
          this.requestPreview(true);
        },
        error: (failure: ApiError) => {
          this.editorLoading = false;
          this.editing = null;
          this.fail(failure);
        },
      });
  }

  /** Back to the list, after asking if there is unsaved work. */
  async close(): Promise<void> {
    if (this.dirty && !(await this.dialog.confirm(this.i18n.t('tenant.email_discard_changes'), { type: 'warning' }))) {
      return;
    }

    this.editing = null;
    this.previewHtml = null;
    this.clearMessages();
  }

  /** Any edit: refresh the preview, a moment after typing stops. */
  changed(): void {
    this.requestPreview();
  }

  setPreviewWidth(width: 'desktop' | 'mobile'): void {
    this.previewWidth = width;
  }

  save(): void {
    const template = this.editing;

    if (template === null || this.saving) {
      return;
    }

    this.saving = true;
    this.clearMessages();

    this.service
      .save(template.type, {
        subject: this.form.subject.trim(),
        body: this.bodyToSend(),
        enabled: this.form.enabled,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (saved) => {
          // The API reports usesDefault/customised itself; a paused template
          // is customised but still sends the default.
          const merged: EmailTemplate = {
            ...template,
            ...saved,
            usesDefault: saved.usesDefault ?? !(saved.enabled && !!(saved.subject || saved.body)),
            customised: saved.customised ?? !!(saved.subject || saved.body),
          };

          this.editing = merged;
          this.pristine = { ...this.form };
          this.replaceInList(merged);
          this.saving = false;
          this.successMessage = this.i18n.t('tenant.saved');
          this.requestPreview(true);
        },
        error: (failure: ApiError) => this.fail(failure),
      });
  }

  async reset(): Promise<void> {
    const template = this.editing;

    if (template === null || !this.isCustomised(template)) {
      return;
    }

    if (!(await this.dialog.confirm(this.i18n.t('tenant.email_confirm_reset'), { type: 'danger' }))) {
      return;
    }

    this.saving = true;
    this.clearMessages();

    this.service
      .reset(template.type)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          const fresh: EmailTemplate = {
            ...template, usesDefault: true, customised: false, subject: null, body: null, enabled: true,
          };

          this.saving = false;
          this.replaceInList(fresh);
          this.editing = fresh;
          this.fillForm(fresh);
          this.successMessage = this.i18n.t('tenant.back_standard_done');
          this.requestPreview(true);
        },
        error: (failure: ApiError) => this.fail(failure),
      });
  }

  testing = false;

  /** Sends the wording as it stands (saved or not) to the admin's own inbox. */
  sendTest(): void {
    const template = this.editing;

    if (template === null || this.testing) {
      return;
    }

    this.testing = true;
    this.clearMessages();

    this.service
      .sendTest(template.type, { subject: this.form.subject.trim(), body: this.bodyToSend() })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.testing = false;
          this.successMessage = this.i18n.t('tenant.email_test_sent', { email: result.sentTo });
        },
        error: (failure: ApiError) => {
          this.testing = false;
          this.fail(failure);
        },
      });
  }

  // --- internals -------------------------------------------------------------

  /**
   * The body to send to the API. A default body the author has not touched
   * goes as '' (= "use the default"), so it is never frozen into the
   * organisation's copy and later improvements to the default still reach it.
   */
  private bodyToSend(): string {
    const t = this.editing;

    if (t && !this.isCustomised(t) && this.form.body === (t.default?.body ?? '')) {
      return '';
    }

    return this.form.body;
  }

  private fillForm(template: EmailTemplate): void {
    // The subject starts empty when the default applies — the default shows as
    // the placeholder — and the body starts from the real default content, so
    // nobody faces a blank page. Save stays off until something changes, so an
    // untouched default is never frozen into the organisation's own copy.
    this.form = {
      subject: template.subject ?? '',
      body: template.body ?? template.default?.body ?? '',
      enabled: template.enabled,
    };
    this.pristine = { ...this.form };
  }

  private replaceInList(template: EmailTemplate): void {
    this.templates = this.templates.map((t) => (t.type === template.type ? { ...t, ...template } : t));
    this.groups = this.buildGroups(this.templates);
  }

  private requestPreview(immediate = false): void {
    if (immediate) {
      this.previewLoading = true;
    }

    this.previewRequests$.next();
  }

  private showPreview(subject: string, html: string): void {
    this.previewSubject = subject;
    // Server-rendered (or locally composed) HTML, shown only inside a frame
    // with an empty sandbox: no scripts, no same-origin access, no forms.
    this.previewHtml = this.sanitizer.bypassSecurityTrustHtml(html);
  }

  private buildGroups(templates: EmailTemplate[]): TemplateGroup[] {
    const placed = new Set<string>();
    const groups: TemplateGroup[] = [];

    for (const g of GROUPS) {
      const items = g.types
        .map((type) => templates.find((t) => t.type === type))
        .filter((t): t is EmailTemplate => t !== undefined);

      items.forEach((t) => placed.add(t.type));

      if (items.length > 0) {
        groups.push({ key: g.key, icon: g.icon, items });
      }
    }

    const rest = templates.filter((t) => !placed.has(t.type));

    if (rest.length > 0) {
      groups.push({ key: 'other', icon: 'bi-envelope', items: rest });
    }

    return groups;
  }

  /** The translation, or null when the dictionary has no entry for the key. */
  private translated(key: string): string | null {
    const value = this.i18n.t(key);

    return value === key ? null : value;
  }

  private fail(failure: ApiError): void {
    this.saving = false;

    const first = failure?.violations ? Object.values(failure.violations).flat()[0] : undefined;

    this.errorMessage = first ?? failure?.message ?? this.i18n.t('tenant.email_error');
  }

  private clearMessages(): void {
    this.successMessage = '';
    this.errorMessage = '';
  }

  // --- local fallback preview ----------------------------------------------------

  private examples(): Record<string, string> {
    const org = this.brandName || 'Your organisation';

    return {
      recipient_name: 'Ada Lovelace',
      organisation: org,
      subject: 'Fire Safety',
      default_body: this.editing?.default?.title ?? '',
      due_date: '30 September 2026',
      starts_at: '30 September 2026, 09:00',
      link: '#',
      expires_in: 'one hour',
      brand_color: this.brandPrimary,
      logo_url: this.brandLogo ?? '',
      site_url: window.location.origin,
    };
  }

  private fill(text: string, escapeValues: boolean): string {
    const values = this.examples();

    return text.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, name: string) => {
      const value = values[name] ?? '';

      return escapeValues ? escapeHtml(value) : value;
    });
  }

  private localSubject(): string {
    const subject = this.form.subject.trim() || this.editing?.default?.title || '';

    return this.fill(subject, false);
  }

  /** A close approximation of the branded layout, for when the API cannot render one. */
  private localPreview(): string {
    const colour = this.brandPrimary;
    const org = escapeHtml(this.brandName || 'Your organisation');
    const logo = this.brandLogo;
    const header = logo
      ? `<img src="${escapeHtml(logo)}" alt="${org}" style="max-height:40px;max-width:160px;display:block">`
      : `<span style="font:600 18px/1.3 Arial,sans-serif;color:#ffffff">${org}</span>`;
    const body = this.fill(this.form.body || this.editing?.default?.body || '', true);

    return `<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:24px 12px;background:#f3f4f6;font:14px/1.6 Arial,Helvetica,sans-serif;color:#1f2937">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb">
<tr><td style="background:${colour};padding:18px 24px">${header}</td></tr>
<tr><td style="padding:28px 24px">${body}</td></tr>
<tr><td style="padding:16px 24px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280">Sent by ${org}</td></tr>
</table></body></html>`;
  }
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
