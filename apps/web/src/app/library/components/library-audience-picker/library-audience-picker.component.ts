import { Component, input, model } from '@angular/core';
import type { AudienceType } from '../../library.service';
import { TranslatePipe } from '../../../translate.pipe';
import { SelectComponent } from '../../../shared/select/select.component';

export interface AudienceOption {
  id: string;
  name: string;
}

/** The selectable groups for each audience type. */
export interface AudienceOptions {
  role: AudienceOption[];
  department: AudienceOption[];
  branch: AudienceOption[];
  team: AudienceOption[];
}

/**
 * Who a library file is shared with: everyone, or chosen departments,
 * branches, teams or roles. Used by both the edit form and the batch upload.
 */
@Component({
  selector: 'app-library-audience-picker',
  standalone: true,
  imports: [TranslatePipe, SelectComponent],
  template: `
    <label class="form-label required" [attr.for]="selectId()">{{ 'library.share_with' | translate }}</label>
    <app-select class="mb-2" [inputId]="selectId()" [disabled]="disabled()"
                [options]="targetTypes" [translateLabels]="true" [searchable]="false"
                [value]="targetType()" (selectionChange)="$event !== targetType() && onType($event)" />

    @if (targetType() !== 'all') {
      @if (current().length === 0) {
        <div class="alert alert-warning py-2 px-3 small mb-0">
          {{ 'library.no_options_defined' | translate:{ type: targetType() } }}
        </div>
      } @else {
        <div class="lap-list" role="group" [attr.aria-label]="'library.share_with' | translate">
          @for (o of current(); track o.id) {
            <label class="lap-pick">
              <input type="checkbox" [disabled]="disabled()" [checked]="targetIds().includes(o.id)" (change)="toggle(o.id)" />
              <span>{{ o.name }}</span>
            </label>
          }
        </div>
        @if (targetIds().length === 0) {
          <div class="form-text text-danger">{{ 'library.audience_pick_one' | translate }}</div>
        }
      }
    }
  `,
  styles: [`
    .lap-list {
      max-height: 200px;
      overflow-y: auto;
      border: 1px solid var(--ui-border, #e5e7eb);
      border-radius: .5rem;
      padding: .35rem;
    }
    .lap-pick {
      display: flex;
      align-items: center;
      gap: .5rem;
      padding: .35rem .5rem;
      border-radius: .35rem;
      cursor: pointer;
      font-size: .875rem;
    }
    .lap-pick:hover { background: var(--ui-surface-2, #f3f4f6); }
  `],
})
export class LibraryAudiencePickerComponent {
  readonly options = input.required<AudienceOptions>();
  readonly disabled = input(false);
  readonly selectId = input('lib-audience');
  readonly targetType = model<AudienceType>('all');
  readonly targetIds = model<string[]>([]);

  readonly targetTypes: Array<{ value: AudienceType; label: string }> = [
    { value: 'all', label: 'library.audience_all' },
    { value: 'department', label: 'library.audience_department' },
    { value: 'branch', label: 'library.audience_branch' },
    { value: 'team', label: 'library.audience_team' },
    { value: 'role', label: 'library.audience_role' },
  ];

  current(): AudienceOption[] {
    const type = this.targetType();
    return type === 'all' ? [] : this.options()[type] ?? [];
  }

  onType(type: AudienceType): void {
    this.targetType.set(type);
    // Ids from one kind do not carry to another.
    this.targetIds.set([]);
  }

  toggle(id: string): void {
    const ids = this.targetIds();
    this.targetIds.set(ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]);
  }
}
