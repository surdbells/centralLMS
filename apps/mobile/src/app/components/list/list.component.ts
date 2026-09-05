import { Component, ChangeDetectionStrategy, booleanAttribute, input, output } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { IonIcon } from '@ionic/angular/standalone';

/**
 * An inset list card replacing ion-list[inset]. A rounded surface that holds
 * <app-item> rows, hairline-divided. Purely presentational — a container.
 */
@Component({
  selector: 'app-list',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<div class="list"><ng-content></ng-content></div>`,
  styles: [`
    :host { display: block; }
    .list {
      background: var(--app-surface); border: var(--app-card-border);
      border-radius: var(--app-r); overflow: hidden; box-shadow: var(--app-shadow);
    }
  `],
})
export class ListComponent {}

/**
 * A row inside <app-list> — a leading icon, a projected label, an optional
 * trailing note, and, when [button], tap feedback + a chevron.
 *
 *   <app-item icon="ribbon-outline" button (tap)="go()">My certificates</app-item>
 *   <app-item icon="briefcase-outline" [note]="role()">Role</app-item>
 */
@Component({
  selector: 'app-item',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IonIcon, NgTemplateOutlet],
  host: { '[class.tappable]': 'button()' },
  template: `
    @if (button()) {
      <button class="item" type="button" (click)="tap.emit()">
        <ng-container [ngTemplateOutlet]="body"></ng-container>
        @if (chevron()) { <ion-icon class="chev" name="chevron-forward" aria-hidden="true"></ion-icon> }
      </button>
    } @else {
      <div class="item">
        <ng-container [ngTemplateOutlet]="body"></ng-container>
      </div>
    }

    <ng-template #body>
      @if (icon()) { <ion-icon class="lead" [class.danger]="tone() === 'danger'" [name]="icon()" aria-hidden="true"></ion-icon> }
      <span class="label" [class.danger]="tone() === 'danger'"><ng-content></ng-content></span>
      @if (note()) { <span class="note">{{ note() }}</span> }
    </ng-template>
  `,
  styles: [`
    :host { display: block; }
    :host(:not(:first-child)) .item { border-top: 1px solid var(--app-line); }

    .item {
      width: 100%; display: flex; align-items: center; gap: 12px;
      padding: 14px 14px; text-align: left; background: transparent; border: 0;
      font: inherit; color: var(--app-ink); font-size: 0.96rem;
    }
    button.item { cursor: pointer; -webkit-tap-highlight-color: transparent; }
    button.item:active { background: var(--app-field); }

    .lead { flex: none; font-size: 20px; color: var(--ion-color-primary); }
    .lead.danger { color: var(--ion-color-danger); }
    .label { flex: 1; min-width: 0; font-weight: 600; }
    .label.danger { color: var(--ion-color-danger); }
    .note { flex: none; color: var(--app-muted); font-size: 0.9rem; max-width: 55%; text-align: right; }
    .chev { flex: none; font-size: 18px; color: var(--app-muted); margin-left: -4px; }
  `],
})
export class ItemComponent {
  readonly icon = input('');
  readonly note = input('');
  readonly tone = input<'default' | 'danger'>('default');
  readonly button = input(false, { transform: booleanAttribute });
  readonly chevron = input(true, { transform: booleanAttribute });
  readonly tap = output<void>();
}
