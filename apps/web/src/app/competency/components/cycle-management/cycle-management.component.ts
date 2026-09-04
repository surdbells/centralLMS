import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HotToastService } from '@ngneat/hot-toast';
import { LookupsService, type Lookup } from '../../../services/lookups.service';

/**
 * Manage the assessment cycles — the periods an exercise runs for.
 *
 * A cycle is a named list item (a lookup of kind "cycle"), so this is a thin
 * screen over the same create / rename / retire the other reference data uses.
 * Retiring a cycle deactivates it rather than deleting it: assessments name
 * their cycle, and removing one would leave those records pointing at nothing —
 * a deactivated cycle simply stops being offered for new work while its history
 * stays intact. Renaming one carries its assessments along (the API sees to it).
 */
@Component({
  selector: 'app-cycle-management',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './cycle-management.component.html',
  styleUrl: './cycle-management.component.scss',
})
export class CycleManagementComponent implements OnInit {
  private readonly lookups = inject(LookupsService);
  private readonly toast = inject(HotToastService);

  readonly cycles = signal<Lookup[]>([]);
  readonly loading = signal(true);
  readonly creating = signal(false);
  readonly savingId = signal<string | null>(null);

  newName = '';
  newDescription = '';

  editingId: string | null = null;
  editName = '';
  editDescription = '';

  ngOnInit(): void {
    this.load();
  }

  private load(): void {
    this.loading.set(true);
    this.lookups.list('cycle').subscribe({
      next: (rows) => {
        // Newest first, active before retired, matching how cycles read.
        this.cycles.set(
          [...rows].sort(
            (a, b) =>
              Number(b.isActive) - Number(a.isActive) ||
              b.name.localeCompare(a.name, undefined, { numeric: true }),
          ),
        );
        this.loading.set(false);
      },
      error: () => {
        this.toast.error('The cycles could not be loaded.');
        this.loading.set(false);
      },
    });
  }

  add(): void {
    const name = this.newName.trim();

    if (!name || this.creating()) {
      return;
    }

    this.creating.set(true);
    this.lookups
      .create('cycle', { name, description: this.newDescription.trim() || null })
      .subscribe({
        next: () => {
          this.creating.set(false);
          this.newName = '';
          this.newDescription = '';
          this.toast.success(`Cycle “${name}” added.`);
          this.load();
        },
        error: (err: unknown) => {
          this.creating.set(false);
          this.toast.error(this.conflict(err) ?? 'That cycle could not be added.');
        },
      });
  }

  startEdit(row: Lookup): void {
    this.editingId = row.id;
    this.editName = row.name;
    this.editDescription = row.description ?? '';
  }

  cancelEdit(): void {
    this.editingId = null;
  }

  saveEdit(row: Lookup): void {
    const name = this.editName.trim();

    if (!name || this.savingId()) {
      return;
    }

    this.savingId.set(row.id);
    this.lookups
      .update('cycle', row.id, { name, description: this.editDescription.trim() || null })
      .subscribe({
        next: () => {
          this.savingId.set(null);
          this.editingId = null;
          this.toast.success('Cycle updated.');
          this.load();
        },
        error: (err: unknown) => {
          this.savingId.set(null);
          this.toast.error(this.conflict(err) ?? 'The cycle could not be updated.');
        },
      });
  }

  toggleStatus(row: Lookup): void {
    this.savingId.set(row.id);
    this.lookups.setStatus('cycle', row.id, !row.isActive).subscribe({
      next: () => {
        this.savingId.set(null);
        this.toast.success(row.isActive ? 'Cycle retired.' : 'Cycle reactivated.');
        this.load();
      },
      error: () => {
        this.savingId.set(null);
        this.toast.error('The cycle status could not be changed.');
      },
    });
  }

  /** A duplicate name comes back as a 409; surface the server's own message. */
  private conflict(err: unknown): string | null {
    const e = err as { status?: number; error?: { message?: string } };
    return e?.status === 409 ? e.error?.message ?? 'A cycle with that name already exists.' : null;
  }
}
