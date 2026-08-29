import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
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
  imports: [CommonModule, FormsModule, TuiIcon],
  templateUrl: './competency-dictionary.component.html',
  styleUrl: './competency-dictionary.component.scss',
})
export class CompetencyDictionaryComponent implements OnInit {
  private readonly competency = inject(CompetencyService);
  private readonly toast = inject(HotToastService);

  loading = true;
  saving = false;
  groups: FunctionGroup[] = [];
  functionFilter = '';
  search = '';
  functionStats: CompetencyFunction[] = [];
  openMenuId: string | null = null;
  /** The card whose level descriptors are expanded. */
  expandedId: string | null = null;

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
  importResult: ImportResult | null = null;

  ngOnInit(): void {
    this.load();
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
          this.toast.error('The dictionary could not be loaded.');
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

  toggleLevels(id: string): void {
    this.expandedId = this.expandedId === id ? null : id;
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
      this.toast.error('A competency needs a name and a function.');
      return;
    }
    this.saving = true;
    const request = this.editing
      ? this.competency.updateCompetency(this.editing.id, this.form)
      : this.competency.createCompetency(this.form);

    request.subscribe({
      next: () => {
        this.toast.success(this.editing ? 'Competency updated.' : 'Competency added.');
        this.saving = false;
        this.closeModal();
        this.load();
      },
      error: (err) => {
        this.saving = false;
        this.toast.error(
          err?.code === 'duplicate_competency'
            ? 'A competency with that name already exists in this function.'
            : 'The competency could not be saved.',
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
        this.toast.success('Competency removed.');
        this.deleteTarget = null;
        this.load();
      },
      error: () => {
        this.toast.error('The competency could not be removed.');
        this.deleteTarget = null;
      },
    });
  }

  // --- import -------------------------------------------------------------

  openImport(): void {
    this.importOpen = true;
    this.importFiles = [];
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

    this.competency.importWorkbooks(this.importFiles).subscribe({
      next: (progress) => {
        this.importProgress = progress.progress;
        if (progress.state === 'done' && progress.result) {
          this.importResult = progress.result;
          this.importing = false;
          const t = progress.result.totals;
          if (t.competencies > 0) {
            this.toast.success(`Imported ${t.competencies} competencies across ${t.functions} functions.`);
          } else {
            this.toast.error('Nothing could be imported from those files.');
          }
        }
      },
      error: () => {
        this.importing = false;
        this.toast.error('The workbooks could not be imported.');
      },
    });
  }

  fileStatusLabel(status: string): string {
    return (
      {
        imported: 'Imported',
        legacy_xls: 'Old .xls format — re-save as .xlsx',
        no_competency_sheets: 'No competency sheets found',
        unreadable: 'Could not be read',
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
