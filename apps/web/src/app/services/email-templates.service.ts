import { Injectable, inject } from '@angular/core';
import { Observable, filter, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface Wording {
  title: string;
  body: string;
}

/**
 * One kind of message, and what this organisation has made of it.
 *
 * `default` is the platform's wording, shown beside the editor so an author
 * can see what they are replacing. `usesDefault` is true until they replace it.
 */
export interface EmailTemplate {
  type: string;
  label: string;
  /** True when the default wording is what is sent (no custom, or a paused one). */
  usesDefault: boolean;
  /** True when the organisation has its own wording saved, paused or not. */
  customised?: boolean;
  subject: string | null;
  body: string | null;
  enabled: boolean;
  updatedAt?: string | null;
  default: Wording;
  /** Only on the single-template read: what this would actually send. */
  preview?: Wording;
  /** Optional server hints; the screen has its own grouping and wording. */
  group?: string | null;
  description?: string | null;
}

/** The organisation's look, as the email layout will apply it (EmailBrandingResolver::summary). */
export interface EmailBranding {
  organisation?: string | null;
  /** The absolute, public logo address emails use; null when there is none an inbox can load. */
  logoUrl?: string | null;
  primaryColor?: string | null;
  textOnPrimary?: string | null;
  siteUrl?: string | null;
  /** A logo is set somewhere (email logo, branding logo or profile logo). */
  logoConfigured?: boolean;
  /** That logo is one an inbox can fetch. */
  logoIsPublic?: boolean;
}

export interface BrandingUploadResult {
  asset: string;
  setting: string;
  url: string;
  isPublic: boolean;
}

export interface EmailTestResult {
  sentTo: string;
  subject: string;
}

export interface EmailTemplateList {
  templates: EmailTemplate[];
  /** What a template may refer to, so the editor can offer them. */
  placeholders: string[];
  /** The branding the layout uses, when the API reports it. */
  branding?: EmailBranding | null;
}

/** Unsaved wording sent for a live preview. Empty means "use the default". */
export interface EmailPreviewInput {
  title: string;
  body: string;
}

/** A fully rendered, branded email: what an inbox would show. */
export interface EmailPreview {
  subject: string;
  html: string;
}

export interface EmailTemplateInput {
  subject?: string;
  body?: string;
  enabled?: boolean;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * What an organisation's own mail says.
 *
 * The platform default is one set of English sentences for every tenant. A
 * company with its own tone, its own signature or its own language overrides
 * the ones it cares about; the rest keep the default, so nothing has to be
 * copied into every organisation and then kept in step.
 *
 * Only the kinds of message that actually go out by email are offered.
 */
@Injectable({ providedIn: 'root' })
export class EmailTemplatesService {
  private readonly api = inject(ApiClient);

  list(): Observable<EmailTemplateList> {
    return this.api.get<EmailTemplateList>(Endpoints.emailTemplates.list);
  }

  /** One, with a preview of what it would send. */
  show(type: string): Observable<EmailTemplate> {
    return this.api.get<EmailTemplate>(Endpoints.emailTemplates.show(type));
  }

  save(type: string, input: EmailTemplateInput): Observable<EmailTemplate> {
    return this.api.put<EmailTemplate>(Endpoints.emailTemplates.save(type), input);
  }

  /** Removes the override, so the platform wording applies again. */
  reset(type: string): Observable<void> {
    return this.api.delete<void>(Endpoints.emailTemplates.delete(type)).pipe(map(() => undefined));
  }

  /**
   * Renders wording that has not been saved yet, inside the organisation's
   * branded layout (logo, colour, footer), exactly as it would be mailed.
   */
  preview(type: string, input: EmailPreviewInput): Observable<EmailPreview> {
    return this.api
      .post<Partial<EmailPreview> & Partial<Wording>>(Endpoints.emailTemplates.preview(type), input)
      .pipe(
        // Tolerate either shape: {subject, html} or the older {title, body}.
        map((r) => ({
          subject: r.subject ?? r.title ?? '',
          html: r.html ?? r.body ?? '',
        })),
      );
  }

  /** Sends the (unsaved) wording, branded, to the signed-in admin's inbox. */
  sendTest(type: string, input: { subject: string; body: string }): Observable<EmailTestResult> {
    return this.api.post<EmailTestResult>(Endpoints.emailTemplates.test(type), input);
  }

  /**
   * Uploads a PNG/JPEG/GIF logo to a public address inboxes can load, and
   * sets the matching Branding setting to it. Emits once, when done.
   */
  uploadLogo(asset: 'logo' | 'email-logo', file: File): Observable<BrandingUploadResult> {
    const form = new FormData();
    form.append('file', file, file.name);

    return this.api.upload<BrandingUploadResult>(Endpoints.settings.brandingUpload(asset), form).pipe(
      filter((e) => e.state === 'done' && !!e.result),
      map((e) => e.result as BrandingUploadResult),
    );
  }
}
