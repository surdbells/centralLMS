import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
import { ApiClient, Endpoints } from '../../../core/api';
import { RichHtmlEditorComponent } from '../../../shared/rich-html-editor/rich-html-editor.component';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import {
  CompetencyService,
  type Competency,
  type CompetencyFunction,
  type CompetencyInput,
  type ImportResult,
} from '../../competency.service';

interface FunctionGroup {
  function: string;
  competencies: Competency[];
}

/**
 * The competency dictionary: every named capability the organisation measures,
 * grouped by the function it belongs to, with what each level of it looks like.
 *
 * A competency is the unit everything else is built from — a profile requires
 * it, an assessment measures it — so the level descriptors live here, written
 * once, rather than being restated on every profile that draws on it.
 */
@Component({
  selector: 'app-competency-dictionary',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, RichHtmlEditorComponent, TranslatePipe],
  templateUrl: './competency-dictionary.component.html',
  styleUrl: './competency-dictionary.component.scss',
})
export class CompetencyDictionaryComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly toast = inject(HotToastService);
  private readonly http = inject(HttpClient);
  private readonly api = inject(ApiClient);
  private readonly i18n = inject(I18nService);

  loading = true;
  saving = false;
  groups: FunctionGroup[] = [];
  functionFilter = '';
  search = '';
  functionStats: CompetencyFunction[] = [];
  openMenuId: string | null = null;
  /** The competency whose levels are shown in the modal. */
  levelsModal: Competency | null = null;
  private readonly searchChanged$ = new Subject<string>();

  get knownFunctions(): string[] {
    return this.functionStats.map((f) => f.function);
  }

  /** Totals for the header — across the whole dictionary, not the filtered view. */
  get totalCompetencies(): number {
    return this.functionStats.reduce((sum, f) => sum + f.competencies, 0);
  }

  get totalProfiles(): number {
    return this.functionStats.reduce((sum, f) => sum + f.profiles, 0);
  }

  /** Competencies shown after the current filter — for the result count. */
  get shownCount(): number {
    return this.groups.reduce((sum, g) => sum + g.competencies.length, 0);
  }

  // Modal state
  modalOpen = false;
  editing: Competency | null = null;
  form: CompetencyInput = this.blankForm();

  // Delete confirmation
  deleteTarget: Competency | null = null;

  // Import
  importOpen = false;
  importing = false;
  importProgress = 0;
  importFiles: File[] = [];
  /** Optional — only some completed workbooks are saved with a password to open. */
  importPassword = '';
  importResult: ImportResult | null = null;

  ngOnInit(): void {
    this.load();
    // Live search: reload as the admin types, once they pause.
    this.searchChanged$
      .pipe(debounceTime(250), distinctUntilChanged())
      .subscribe(() => this.load());
  }

  onSearch(value: string): void {
    this.search = value;
    this.searchChanged$.next(value);
  }

  /** Downloads the blank JCP workbook to fill in. */
  downloadTemplate(): void {
    this.http
      .get(this.api.urlFor(Endpoints.competencies.importTemplate), { responseType: 'blob' })
      .subscribe({
        next: (blob) => {
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = 'JCP-template.xlsx';
          link.click();
          URL.revokeObjectURL(url);
        },
        error: () => this.toast.error(this.i18n.t('competency.dict.download_error')),
      });
  }

  openLevels(competency: Competency): void {
    this.levelsModal = competency;
  }

  closeLevels(): void {
    this.levelsModal = null;
  }

  load(): void {
    this.loading = true;
    this.competency
      .getCompetencies({ search: this.search || undefined, function: this.functionFilter || undefined, perPage: 200 })
      .subscribe({
        next: (paged) => {
          this.groups = this.group(paged.items);
          this.loading = false;
        },
        error: () => {
          this.toast.error(this.i18n.t('competency.dict.load_error'));
          this.loading = false;
        },
      });

    // Per-function counts for the chip bar, the header totals and the datalist.
    this.competency.getFunctions().subscribe({
      next: (functions) => (this.functionStats = functions),
      error: () => undefined,
    });
  }

  /** Filter to one function (or clear) from the chip bar. */
  selectFunction(fn: string): void {
    this.functionFilter = this.functionFilter === fn ? '' : fn;
    this.load();
  }

  private group(items: Competency[]): FunctionGroup[] {
    const byFunction = new Map<string, Competency[]>();
    for (const item of items) {
      const list = byFunction.get(item.functionName) ?? [];
      list.push(item);
      byFunction.set(item.functionName, list);
    }
    return [...byFunction.entries()]
      .map(([func, competencies]) => ({ function: func, competencies }))
      .sort((a, b) => a.function.localeCompare(b.function));
  }

  // --- the modal ----------------------------------------------------------

  openNew(): void {
    this.editing = null;
    this.form = this.blankForm();
    this.modalOpen = true;
  }

  openEdit(competency: Competency): void {
    this.editing = competency;
    this.form = {
      name: competency.name,
      functionName: competency.functionName,
      description: competency.description ?? '',
      knowledgeDescriptor: competency.levels.knowledge ?? '',
      skillDescriptor: competency.levels.skill ?? '',
      masteryDescriptor: competency.levels.mastery ?? '',
    };
    this.modalOpen = true;
  }

  closeModal(): void {
    this.modalOpen = false;
    this.editing = null;
  }

  save(): void {
    if (!this.form.name.trim() || !this.form.functionName.trim()) {
      this.toast.error(this.i18n.t('competency.dict.validation'));
      return;
    }
    this.saving = true;
    const request = this.editing
      ? this.competency.updateCompetency(this.editing.id, this.form)
      : this.competency.createCompetency(this.form);

    request.subscribe({
      next: () => {
        this.toast.success(this.i18n.t(this.editing ? 'competency.dict.updated' : 'competency.dict.added'));
        this.saving = false;
        this.closeModal();
        this.load();
      },
      error: (err) => {
        this.saving = false;
        this.toast.error(
          this.i18n.t(
            err?.code === 'duplicate_competency'
              ? 'competency.dict.duplicate'
              : 'competency.dict.save_error',
          ),
        );
      },
    });
  }

  // --- delete -------------------------------------------------------------

  askDelete(competency: Competency): void {
    this.deleteTarget = competency;
  }

  cancelDelete(): void {
    this.deleteTarget = null;
  }

  confirmDelete(): void {
    if (!this.deleteTarget) {
      return;
    }
    const target = this.deleteTarget;
    this.competency.deleteCompetency(target.id).subscribe({
      next: () => {
        this.toast.success(this.i18n.t('competency.dict.removed'));
        this.deleteTarget = null;
        this.load();
      },
      error: () => {
        this.toast.error(this.i18n.t('competency.dict.remove_error'));
        this.deleteTarget = null;
      },
    });
  }

  // --- import -------------------------------------------------------------

  openImport(): void {
    this.importOpen = true;
    this.importFiles = [];
    this.importPassword = '';
    this.importResult = null;
    this.importProgress = 0;
  }

  closeImport(): void {
    if (this.importing) {
      return;
    }
    this.importOpen = false;
    // If anything came in, the dictionary behind the modal is now stale.
    if (this.importResult && this.importResult.totals.competencies > 0) {
      this.load();
    }
  }

  onFilesPicked(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.importFiles = input.files ? Array.from(input.files) : [];
    this.importResult = null;
  }

  runImport(): void {
    if (!this.importFiles.length) {
      return;
    }
    this.importing = true;
    this.importProgress = 0;
    this.importResult = null;

    this.competency.importWorkbooks(this.importFiles, this.importPassword || undefined).subscribe({
      next: (progress) => {
        this.importProgress = progress.progress;
        if (progress.state === 'done' && progress.result) {
          this.importResult = progress.result;
          this.importing = false;
          const t = progress.result.totals;
          if (t.competencies > 0) {
            this.toast.success(
              this.i18n.t('competency.dict.import_success', {
                competencies: t.competencies,
                functions: t.functions,
              }),
            );
          } else {
            this.toast.error(this.i18n.t('competency.dict.import_nothing'));
          }
        }
      },
      error: () => {
        this.importing = false;
        this.toast.error(this.i18n.t('competency.dict.import_error'));
      },
    });
  }

  fileStatusLabel(status: string): string {
    return (
      {
        imported: this.i18n.t('competency.dict.status_imported'),
        legacy_xls: this.i18n.t('competency.dict.status_legacy_xls'),
        no_competency_sheets: this.i18n.t('competency.dict.status_no_sheets'),
        password_required: this.i18n.t('competency.dict.status_password_required'),
        unreadable: this.i18n.t('competency.dict.status_unreadable'),
      }[status] ?? status
    );
  }

  private blankForm(): CompetencyInput {
    return {
      name: '',
      functionName: this.functionFilter || '',
      description: '',
      knowledgeDescriptor: '',
      skillDescriptor: '',
      masteryDescriptor: '',
    };
  }
}
