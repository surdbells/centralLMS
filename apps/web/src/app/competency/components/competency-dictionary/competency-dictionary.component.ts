import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TuiIcon } from '@taiga-ui/core';
import { HotToastService } from '@ngneat/hot-toast';
import {
  CompetencyService,
  type Competency,
  type CompetencyInput,
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
  knownFunctions: string[] = [];
  openMenuId: string | null = null;

  // Modal state
  modalOpen = false;
  editing: Competency | null = null;
  form: CompetencyInput = this.blankForm();

  // Delete confirmation
  deleteTarget: Competency | null = null;

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

    // Function list for the filter and the form's datalist.
    this.competency.getFunctions().subscribe({
      next: (functions) => (this.knownFunctions = functions.map((f) => f.function)),
      error: () => undefined,
    });
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
