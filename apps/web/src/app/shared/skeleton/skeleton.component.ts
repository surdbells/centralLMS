import { ChangeDetectionStrategy, Component, Input, OnDestroy } from '@angular/core';
import { NgFor, NgIf, NgSwitch, NgSwitchCase, NgSwitchDefault, NgTemplateOutlet } from '@angular/common';
import { TranslatePipe } from '../../translate.pipe';

/**
 * The app's loading state: a shimmering placeholder shaped like the content
 * that is on its way, as on the My Learning page — never a bare spinner.
 *
 * Pick the variant that matches what will appear, so the page doesn't jump
 * when the data lands:
 *
 *   <app-skeleton variant="page" />                     whole page: header + KPIs + table
 *   <app-skeleton variant="kpis" [count]="4" />         a row of KPI cards
 *   <app-skeleton variant="cards" [count]="6" />        a grid of cards (cover + text)
 *   <app-skeleton variant="table" [rows]="6" [cols]="5" />  a table
 *   <app-skeleton variant="list" [rows]="5" />          avatar + two-line rows
 *   <app-skeleton variant="form" [rows]="4" />          label + field pairs
 *   <app-skeleton variant="detail" />                   title, meta, a paragraph and a block
 *   <app-skeleton variant="lines" [rows]="3" />         a few text lines (small inline areas)
 *   <app-skeleton variant="block" height="240px" />     one block (a chart, a media area)
 *
 * It announces itself once to screen readers ("Loading…") and hides the
 * decorative blocks from them. Spinners stay only inside buttons.
 */
export type SkeletonVariant = 'page' | 'kpis' | 'cards' | 'table' | 'list' | 'form' | 'detail' | 'lines' | 'block';

@Component({
  selector: 'app-skeleton',
  standalone: true,
  imports: [NgFor, NgIf, NgSwitch, NgSwitchCase, NgSwitchDefault, NgTemplateOutlet, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    // Only the first skeleton on screen is a live region, so a page that draws
    // several (a dashboard, the player shell) announces "Loading…" once.
    '[attr.role]': 'announce ? "status" : null',
    '[attr.aria-live]': 'announce ? "polite" : null',
    '[attr.aria-hidden]': 'announce ? null : "true"',
    'aria-busy': 'true',
    '[class]': '"sk-host sk-" + variant',
  },
  template: `
    <span class="visually-hidden" *ngIf="announce">{{ 'common.loading' | translate }}</span>

    <ng-container [ngSwitch]="variant">
      <!-- Whole page: header, KPI row and a table section. -->
      <ng-container *ngSwitchCase="'page'">
        <div class="sk-header" aria-hidden="true">
          <span class="ui-skeleton sk-tile"></span>
          <div class="sk-header-text">
            <span class="ui-skeleton sk-line w-40 sk-title"></span>
            <span class="ui-skeleton sk-line w-60"></span>
          </div>
        </div>
        <ng-container *ngTemplateOutlet="kpis"></ng-container>
        <div class="sk-section" aria-hidden="true">
          <span class="ui-skeleton sk-line w-30 sk-title"></span>
          <span class="ui-skeleton sk-line w-50"></span>
          <ng-container *ngTemplateOutlet="table"></ng-container>
        </div>
      </ng-container>

      <ng-container *ngSwitchCase="'kpis'"><ng-container *ngTemplateOutlet="kpis"></ng-container></ng-container>
      <ng-container *ngSwitchCase="'table'"><ng-container *ngTemplateOutlet="table"></ng-container></ng-container>

      <div *ngSwitchCase="'cards'" class="sk-cards" aria-hidden="true">
        <div class="sk-card" *ngFor="let c of countArr">
          <span class="ui-skeleton sk-cover"></span>
          <div class="sk-card-body">
            <span class="ui-skeleton sk-line w-40"></span>
            <span class="ui-skeleton sk-line w-90"></span>
            <span class="ui-skeleton sk-line w-70"></span>
            <span class="ui-skeleton sk-bar"></span>
          </div>
        </div>
      </div>

      <div *ngSwitchCase="'list'" class="sk-list" aria-hidden="true">
        <div class="sk-list-row" *ngFor="let r of rowsArr; let i = index">
          <span class="ui-skeleton sk-avatar"></span>
          <div class="sk-list-text">
            <span class="ui-skeleton sk-line" [class.w-50]="i % 2 === 0" [class.w-40]="i % 2 === 1"></span>
            <span class="ui-skeleton sk-line w-70"></span>
          </div>
          <span class="ui-skeleton sk-pill"></span>
        </div>
      </div>

      <div *ngSwitchCase="'form'" class="sk-form" aria-hidden="true">
        <div class="sk-field" *ngFor="let r of rowsArr">
          <span class="ui-skeleton sk-line w-20"></span>
          <span class="ui-skeleton sk-input"></span>
        </div>
      </div>

      <div *ngSwitchCase="'detail'" class="sk-detail" aria-hidden="true">
        <span class="ui-skeleton sk-line w-50 sk-title"></span>
        <span class="ui-skeleton sk-line w-30"></span>
        <span class="ui-skeleton sk-line w-90"></span>
        <span class="ui-skeleton sk-line w-80"></span>
        <span class="ui-skeleton sk-line w-60"></span>
        <span class="ui-skeleton sk-block" [style.height]="height || '180px'"></span>
      </div>

      <span *ngSwitchCase="'block'" class="ui-skeleton sk-block" aria-hidden="true" [style.height]="height || '200px'"></span>

      <div *ngSwitchDefault class="sk-lines" aria-hidden="true">
        <span class="ui-skeleton sk-line" *ngFor="let r of rowsArr; let last = last"
              [class.w-90]="!last" [class.w-60]="last"></span>
      </div>
    </ng-container>

    <ng-template #kpis>
      <div class="sk-kpis" aria-hidden="true">
        <div class="sk-kpi" *ngFor="let k of countArr">
          <span class="ui-skeleton sk-line w-60"></span>
          <span class="ui-skeleton sk-num"></span>
        </div>
      </div>
    </ng-template>

    <ng-template #table>
      <div class="sk-table" aria-hidden="true">
        <div class="sk-tr sk-thead" [style.grid-template-columns]="gridCols">
          <span class="ui-skeleton sk-line" *ngFor="let c of colsArr"></span>
        </div>
        <div class="sk-tr" *ngFor="let r of rowsArr" [style.grid-template-columns]="gridCols">
          <span class="ui-skeleton sk-line" *ngFor="let c of colsArr; let j = index"
                [class.w-90]="j === 0" [class.w-60]="j > 0"></span>
        </div>
      </div>
    </ng-template>
  `,
  styles: [`
    :host { display: block; }
    :host(.sk-block), :host(.sk-lines) { width: 100%; }

    .sk-line { height: 10px; margin-bottom: 10px; }
    .sk-line:last-child { margin-bottom: 0; }
    .sk-title { height: 14px; }
    .sk-num  { height: 28px; width: 56px; margin-top: 4px; }
    .sk-bar  { height: 6px; margin-top: 8px; }
    .w-20 { width: 20%; } .w-30 { width: 30%; } .w-40 { width: 40%; } .w-50 { width: 50%; }
    .w-60 { width: 60%; } .w-70 { width: 70%; } .w-80 { width: 80%; } .w-90 { width: 90%; }

    .sk-header { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; }
    .sk-tile { width: 44px; height: 44px; border-radius: var(--ui-radius-card, 12px); flex-shrink: 0; }
    .sk-header-text { flex: 1; max-width: 420px; }

    .sk-kpis {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px; margin-bottom: 20px;
    }
    .sk-kpi, .sk-card, .sk-section {
      background: var(--ui-surface, #fff);
      border: 1px solid var(--ui-border, #e7e9e7);
      border-radius: var(--ui-radius-card, 12px);
    }
    .sk-kpi { padding: 16px 18px; display: flex; flex-direction: column; gap: 6px; }
    .sk-kpi .sk-line { margin-bottom: 0; }

    .sk-section { padding: 20px 22px 22px; border-radius: var(--ui-radius-panel, 16px); }
    .sk-section > .sk-line { margin-bottom: 8px; }
    .sk-section .sk-table { margin-top: 16px; }

    .sk-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
    .sk-card { overflow: hidden; }
    .sk-cover { height: auto; aspect-ratio: 16 / 9; border-radius: 0; }
    .sk-card-body { padding: 16px; }

    .sk-table { border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-card, 12px); overflow: hidden; }
    .sk-tr {
      display: grid;
      gap: 24px; align-items: center; padding: 0 16px; height: 56px;
      border-bottom: 1px solid var(--ui-border, #e7e9e7);
    }
    .sk-tr:last-child { border-bottom: 0; }
    .sk-tr .sk-line { margin: 0; }
    .sk-thead { height: 40px; background: var(--ui-surface-2, #f7f8f7); }
    .sk-thead .sk-line { height: 8px; width: 50%; background: #e6e8e6; }

    .sk-list-row {
      display: flex; align-items: center; gap: 12px; padding: 12px 0;
      border-bottom: 1px solid var(--ui-border, #e7e9e7);
    }
    .sk-list-row:last-child { border-bottom: 0; }
    .sk-avatar { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0; }
    .sk-list-text { flex: 1; min-width: 0; }
    .sk-list-text .sk-line { margin-bottom: 8px; }
    .sk-list-text .sk-line:last-child { margin-bottom: 0; }
    .sk-pill { width: 64px; height: 22px; border-radius: 999px; flex-shrink: 0; }

    .sk-form { display: flex; flex-direction: column; gap: 18px; }
    .sk-field .sk-line { margin-bottom: 8px; }
    .sk-input { height: var(--ui-ctl-h, 36px); border-radius: var(--ui-radius-ctl, 8px); }

    .sk-detail .sk-line { margin-bottom: 12px; }
    .sk-block { width: 100%; border-radius: var(--ui-radius-card, 12px); }
    .sk-detail .sk-block { margin-top: 8px; }

    @media (max-width: 767.98px) {
      .sk-tr { grid-template-columns: 1.6fr 1fr 1fr !important; }
      .sk-tr .sk-line:nth-child(n + 4) { display: none; }
    }
  `],
})
export class SkeletonComponent implements OnDestroy {
  /** How many skeletons are on screen; the first one does the announcing. */
  private static onScreen = 0;
  readonly announce = SkeletonComponent.onScreen++ === 0;

  ngOnDestroy(): void {
    SkeletonComponent.onScreen = Math.max(0, SkeletonComponent.onScreen - 1);
  }

  @Input() variant: SkeletonVariant = 'lines';
  /** Cards or KPI tiles to draw. */
  @Input() count = 4;
  /** Rows for table / list / form / lines. */
  @Input() rows = 5;
  /** Columns for a table. */
  @Input() cols = 5;
  /** Height for a block (and the block in a detail). */
  @Input() height: string | null = null;

  get countArr(): number[] { return Array.from({ length: Math.max(1, this.count) }, (_, i) => i); }
  get rowsArr(): number[] { return Array.from({ length: Math.max(1, this.rows) }, (_, i) => i); }
  /** First column wider (a name), the rest even — like a real data table. */
  get gridCols(): string { return this.cols > 1 ? `1.6fr repeat(${this.cols - 1}, 1fr)` : "1fr"; }

  get colsArr(): number[] { return Array.from({ length: Math.max(1, this.cols) }, (_, i) => i); }
}
