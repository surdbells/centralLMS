import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ReportService } from '../../services/report.service';
import { SavedReport } from '../../models/report.models';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TranslatePipe} from '../../../translate.pipe';
import {I18nService} from '../../../i18n.service';
import { ConfirmDialogService } from '../../../shared/components/confirmation-dialog/confirmation-dialog.component';

@Component({
  selector: 'app-saved-reports',
  standalone: true,
  imports: [CommonModule, RouterLink, AdmintopComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
      <div class="ui-page saved-reports">
        <header class="ui-page-header">
          <a routerLink="/admin/reports" class="ui-page-header__back" [attr.aria-label]="'reports.back_to_dashboard' | translate" [title]="'reports.back_to_dashboard' | translate">
            <i class="bi bi-chevron-left"></i>
          </a>
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-bookmark"></i></div>
          <div class="ui-page-header__titles">
            <h1>{{ 'reports.saved_reports' | translate }}</h1>
            <p>{{ 'reports.saved_subtitle' | translate }}</p>
          </div>
          <div class="ui-page-header__actions">
            <a routerLink="/admin/reports/catalog" class="btn btn-outline-secondary">
              <i class="bi bi-grid-3x3-gap me-1"></i>{{ 'reports.browse_reports' | translate }}
            </a>
          </div>
        </header>

        <section class="ui-section" *ngIf="saved.length">
          <ul class="ui-list">
            <li *ngFor="let s of saved">
              <span class="sr-icon" aria-hidden="true"><i class="bi bi-file-earmark-bar-graph"></i></span>
              <div class="sr-main">
                <a [routerLink]="['/admin/reports', s.report_key]" class="sr-name">{{ s.name }}</a>
                <p class="ui-muted small mb-0">{{ s.report_name || s.report_key }} &middot; {{ 'reports.saved_prefix' | translate }} {{ s.created_at | date:'mediumDate' }}</p>
              </div>
              <div class="d-flex gap-2 flex-shrink-0">
                <a [routerLink]="['/admin/reports', s.report_key]" [queryParams]="s.filters" class="btn btn-sm btn-primary">
                  <i class="bi bi-play-fill"></i> {{ 'reports.run' | translate }}
                </a>
                <button type="button" class="btn btn-sm btn-outline-danger" (click)="remove(s)" [attr.aria-label]="'common.delete' | translate"><i class="bi bi-trash"></i></button>
              </div>
            </li>
          </ul>
        </section>

        <div *ngIf="!saved.length" class="ui-state">
          <span class="ui-state__icon"><i class="bi bi-bookmark"></i></span>
          <h3>{{ 'reports.no_saved' | translate }}</h3>
          <p>{{ 'reports.no_saved_hint' | translate }}</p>
          <a routerLink="/admin/reports/catalog" class="btn btn-primary">{{ 'reports.browse_reports' | translate }}</a>
        </div>
      </div>
    </main>
  `,
  styles: [`
    .sr-icon { width: 36px; height: 36px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
      border-radius: 10px; background: rgba(var(--brand-primary-rgb), .08); color: var(--brand-primary); }
    .sr-main { flex: 1; min-width: 0; }
    .sr-name { font-weight: 600; font-size: .9375rem; color: var(--ui-text); text-decoration: none; }
    .sr-name:hover { color: var(--brand-primary); }
    @media (max-width: 575.98px) { .ui-list > li { flex-wrap: wrap; } }
  `]
})
export class SavedReportsComponent implements OnInit {
  saved: SavedReport[] = [];
  constructor(private svc: ReportService, private i18n: I18nService, private dialog: ConfirmDialogService) {}
  ngOnInit(): void { this.load(); }
  private load(): void { this.svc.getSavedReports().subscribe(d => this.saved = d); }
  async remove(s: SavedReport): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('reports.confirm_delete', { name: s.name }), { type: 'danger' }))) return;
    this.svc.deleteSavedReport(s.id).subscribe(() => this.load());
  }
}
