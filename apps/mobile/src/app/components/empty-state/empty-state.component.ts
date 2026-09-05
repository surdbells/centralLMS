import { Component, EventEmitter, Input, Output } from '@angular/core';
import { IonIcon } from '@ionic/angular/standalone';

/**
 * One look for every "nothing here" and "couldn't load" state — a soft icon
 * badge, a line, and an optional action — so they read the same across screens.
 */
@Component({
  selector: 'app-empty-state',
  standalone: true,
  imports: [IonIcon],
  template: `
    <div class="es rise">
      <div class="es-badge"><ion-icon [name]="icon"></ion-icon></div>
      <p class="es-title">{{ title }}</p>
      @if (message) { <p class="es-msg">{{ message }}</p> }
      @if (actionLabel) {
        <button type="button" class="es-action" (click)="action.emit()">{{ actionLabel }}</button>
      }
    </div>
  `,
  styles: [`
    .es {
      display: flex; flex-direction: column; align-items: center; text-align: center;
      padding: 44px 24px; color: var(--ion-color-medium);
    }
    .es-badge {
      width: 76px; height: 76px; border-radius: 24px; display: grid; place-items: center;
      margin-bottom: 16px;
      background: color-mix(in srgb, var(--ion-color-primary) 12%, transparent);
    }
    .es-badge ion-icon { font-size: 34px; color: var(--ion-color-primary); }
    .es-title { margin: 0; font-size: 1rem; font-weight: 700; color: var(--ion-text-color); }
    .es-msg { margin: 6px 0 0; font-size: .88rem; max-width: 20rem; line-height: 1.45; }
    .es-action {
      margin-top: 16px; background: none; border: 0; padding: 8px 4px;
      color: var(--ion-color-primary); font-weight: 700; font-size: .92rem; cursor: pointer;
    }
    .es-action:active { opacity: .6; }
  `],
})
export class EmptyStateComponent {
  @Input() icon = 'ellipse-outline';
  @Input() title = '';
  @Input() message = '';
  @Input() actionLabel = '';
  @Output() action = new EventEmitter<void>();
}
