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
    <main class="main px-lg-6">
      <div class="container-fluid">
    <div class="saved-reports">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-bookmark me-2"></i>{{ 'reports.saved_reports' | translate }}</h4>
        <a routerLink="/admin/reports" class="btn btn-primary btn-sm">
          <i class="bi bi-arrow-left me-1"></i>{{ 'reports.back_to_dashboard' | translate }}
        </a>
      </div>

      <div class="list-group" *ngIf="saved.length">
        <div *ngFor="let s of saved" class="list-group-item d-flex justify-content-between align-items-center">
          <div>
            <a [routerLink]="['/admin/reports', s.report_key]" class="fw-semibold text-decoration-none">{{ s.name }}</a>
            <p class="text-muted small mb-0">{{ s.report_name || s.report_key }} &middot; {{ 'reports.saved_prefix' | translate }} {{ s.created_at | date:'mediumDate' }}</p>
          </div>
          <div class="d-flex gap-2">
            <a [routerLink]="['/admin/reports', s.report_key]" [queryParams]="s.filters" class="btn btn-sm btn-primary">
              <i class="bi bi-play-fill"></i> {{ 'reports.run' | translate }}
            </a>
            <button class="btn btn-sm btn-outline-danger" (click)="remove(s)"><i class="bi bi-trash"></i></button>
          </div>
        </div>
      </div>

      <div *ngIf="!saved.length" class="text-center py-5 text-muted">
        <i class="bi bi-bookmark fs-1 d-block mb-2"></i>
        <p>{{ 'reports.no_saved' | translate }}</p>
        <a routerLink="/admin/reports/catalog" class="btn btn-primary">{{ 'reports.browse_reports' | translate }}</a>
      </div>
    </div>
      </div>
    </main>
  `
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
