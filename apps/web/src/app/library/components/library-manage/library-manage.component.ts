import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HotToastService } from '@ngneat/hot-toast';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';
import { ApiClient, Endpoints } from '../../../core/api';
import { LibraryViewerComponent } from '../library-viewer/library-viewer.component';
import {
  LibraryService,
  type LibraryItem,
  type LibraryKind,
  type AudienceType,
} from '../../library.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { ConfirmDialogService } from '../../../shared/components/confirmation-dialog/confirmation-dialog.component';

interface Option {
  id: string;
  name: string;
}

/**
 * Managing the digital library: what is shared, and sharing more.
 *
 * An administrator uploads a file, gives it an audience — everyone, or a
 * department, branch, team or role — and it appears for exactly those people.
 * Office documents are converted for viewing in the background, so a freshly
 * uploaded one shows as "preparing" until its PDF is ready.
 */
@Component({
  selector: 'app-library-manage',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent, LibraryViewerComponent, TranslatePipe],
  templateUrl: './library-manage.component.html',
  styleUrl: './library-manage.component.scss',
})
export class LibraryManageComponent implements OnInit {
  private readonly library = inject(LibraryService);
  private readonly api = inject(ApiClient);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);
  private readonly dialog = inject(ConfirmDialogService);

  readonly loading = signal(true);
  readonly items = signal<LibraryItem[]>([]);
  search = '';

  // --- the upload / edit dialog ---
  readonly showForm = signal(false);
  readonly saving = signal(false);
  readonly progress = signal(0);
  editing: LibraryItem | null = null;
  file: File | null = null;
  form = { title: '', description: '', targetType: 'all' as AudienceType, targetIds: [] as string[] };

  // --- audience options, loaded once ---
  readonly roles = signal<Option[]>([]);
  readonly departments = signal<Option[]>([]);
  readonly branches = signal<Option[]>([]);
  readonly teams = signal<Option[]>([]);

  readonly preview = signal<LibraryItem | null>(null);

  readonly targetTypes: Array<{ value: AudienceType; label: string }> = [
    { value: 'all', label: 'library.audience_all' },
    { value: 'department', label: 'library.audience_department' },
    { value: 'branch', label: 'library.audience_branch' },
    { value: 'team', label: 'library.audience_team' },
    { value: 'role', label: 'library.audience_role' },
  ];

  ngOnInit(): void {
    this.load();
    this.loadOptions();
  }

  load(): void {
    this.loading.set(true);
    this.library.listManage(this.search || undefined).subscribe({
      next: (paged) => {
        this.items.set(paged.items);
        this.loading.set(false);
      },
      error: () => {
        this.toast.error(this.i18n.t('library.load_error'));
        this.loading.set(false);
      },
    });
  }

  private loadOptions(): void {
    this.api.get<Option[]>(Endpoints.roles.list).subscribe({ next: (r) => this.roles.set(r ?? []), error: () => undefined });

    const kinds: Array<['department' | 'branch' | 'team', ReturnType<typeof signal<Option[]>>]> = [
      ['department', this.departments],
      ['branch', this.branches],
      ['team', this.teams],
    ];
    for (const [kind, target] of kinds) {
      this.api.get<Option[]>(Endpoints.lookups.active(kind)).subscribe({
        next: (rows) => target.set(rows ?? []),
        error: () => undefined,
      });
    }
  }

  /** The options for the currently chosen target type. */
  options(): Option[] {
    switch (this.form.targetType) {
      case 'role': return this.roles();
      case 'department': return this.departments();
      case 'branch': return this.branches();
      case 'team': return this.teams();
      default: return [];
    }
  }

  openUpload(): void {
    this.editing = null;
    this.file = null;
    this.form = { title: '', description: '', targetType: 'all', targetIds: [] };
    this.progress.set(0);
    this.showForm.set(true);
  }

  openEdit(item: LibraryItem): void {
    this.editing = item;
    this.file = null;
    this.form = {
      title: item.title,
      description: item.description ?? '',
      targetType: item.audience.type,
      targetIds: this.criteriaIds(item),
    };
    this.showForm.set(true);
  }

  closeForm(): void {
    if (this.saving()) {
      return;
    }
    this.showForm.set(false);
  }

  onFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.file = input.files && input.files.length ? input.files[0] : null;
    if (this.file && !this.form.title) {
      this.form.title = this.file.name.replace(/\.[^.]+$/, '');
    }
  }

  onTargetTypeChange(): void {
    // Ids from one kind do not carry to another.
    this.form.targetIds = [];
  }

  toggleId(id: string): void {
    this.form.targetIds = this.form.targetIds.includes(id)
      ? this.form.targetIds.filter((x) => x !== id)
      : [...this.form.targetIds, id];
  }

  canSave(): boolean {
    if (!this.form.title.trim()) {
      return false;
    }
    if (this.form.targetType !== 'all' && this.form.targetIds.length === 0) {
      return false;
    }
    // A new item needs a file; an edit keeps its file.
    return this.editing !== null || this.file !== null;
  }

  save(): void {
    if (!this.canSave()) {
      return;
    }
    const input = {
      title: this.form.title.trim(),
      description: this.form.description.trim() || undefined,
      targetType: this.form.targetType,
      targetIds: this.form.targetIds,
    };

    this.saving.set(true);

    if (this.editing) {
      this.library.update(this.editing.id, input).subscribe({
        next: () => {
          this.toast.success(this.i18n.t('library.toast_updated'));
          this.saving.set(false);
          this.showForm.set(false);
          this.load();
        },
        error: () => {
          this.toast.error(this.i18n.t('library.toast_save_failed'));
          this.saving.set(false);
        },
      });
      return;
    }

    this.library.upload(this.file as File, input).subscribe({
      next: (p) => {
        this.progress.set(p.progress);
        if (p.state === 'done') {
          this.toast.success(this.i18n.t('library.toast_shared'));
          this.saving.set(false);
          this.showForm.set(false);
          this.load();
        }
      },
      error: () => {
        this.toast.error(this.i18n.t('library.toast_upload_failed'));
        this.saving.set(false);
      },
    });
  }

  async remove(item: LibraryItem): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('library.remove_confirm', { title: item.title }), { type: 'danger' }))) {
      return;
    }
    this.library.delete(item.id).subscribe({
      next: () => {
        this.toast.success(this.i18n.t('library.toast_removed'));
        this.items.set(this.items().filter((x) => x.id !== item.id));
      },
      error: () => this.toast.error(this.i18n.t('library.toast_remove_failed')),
    });
  }

  private criteriaIds(item: LibraryItem): string[] {
    const values = Object.values(item.audience.criteria ?? {});
    return values.length ? (values[0] as string[]) : [];
  }

  icon(kind: LibraryKind): string {
    return { video: 'bi-play-btn', audio: 'bi-music-note-beamed', pdf: 'bi-file-earmark-pdf', document: 'bi-file-earmark-text', image: 'bi-image' }[kind];
  }

  kindClass(kind: LibraryKind): string {
    return { video: 'k-video', audio: 'k-audio', pdf: 'k-pdf', document: 'k-doc', image: 'k-image' }[kind];
  }

  statusLabel(item: LibraryItem): string | null {
    switch (item.conversionStatus) {
      case 'pending':
      case 'processing': return this.i18n.t('library.status_preparing');
      case 'failed': return this.i18n.t('library.status_conversion_failed');
      default: return null;
    }
  }

  size(bytes: number): string {
    if (bytes <= 0) {
      return '';
    }
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    return `${(bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }
}
