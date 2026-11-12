import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ReportService } from '../../services/report.service';
import { ScheduledReport, ReportCatalogItem } from '../../models/report.models';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TranslatePipe} from '../../../translate.pipe';
import {I18nService} from '../../../i18n.service';
import { ConfirmDialogService } from '../../../shared/components/confirmation-dialog/confirmation-dialog.component';

@Component({
  selector: 'app-report-scheduler',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
      <div class="ui-page report-scheduler">
        <header class="ui-page-header">
          <a routerLink="/admin/reports" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate" [title]="'common.back' | translate">
            <i class="bi bi-chevron-left"></i>
          </a>
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-clock-history"></i></div>
          <div class="ui-page-header__titles">
            <h1>{{ 'reports.scheduled_reports' | translate }}</h1>
            <p>{{ 'reports.scheduled_subtitle' | translate }}</p>
          </div>
          <div class="ui-page-header__actions">
            <button type="button" class="btn btn-primary" (click)="showForm=!showForm"><i class="bi bi-plus-lg me-1"></i>{{ 'reports.new_schedule' | translate }}</button>
          </div>
        </header>

        <!-- New Schedule Form -->
        <section class="ui-section" *ngIf="showForm">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">{{ 'reports.create_schedule' | translate }}</h2></div>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label small">{{ 'reports.report_label' | translate }}</label>
              <select class="form-select" [(ngModel)]="newSchedule.report_key">
                <option value="">{{ 'reports.select_report' | translate }}</option>
                <option *ngFor="let r of reportOptions" [value]="r.key">{{ r.name }}</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small">{{ 'reports.frequency' | translate }}</label>
              <select class="form-select" [(ngModel)]="newSchedule.frequency">
                <option value="daily">{{ 'reports.freq_daily' | translate }}</option>
                <option value="weekly">{{ 'reports.freq_weekly' | translate }}</option>
                <option value="monthly">{{ 'reports.freq_monthly' | translate }}</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small">{{ 'reports.format' | translate }}</label>
              <select class="form-select" [(ngModel)]="newSchedule.export_format">
                <option value="csv">CSV</option>
                <option value="xlsx">{{ 'reports.excel' | translate }}</option>
                <option value="pdf">PDF</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small">{{ 'reports.recipients_emails' | translate }}</label>
              <input type="text" class="form-control" [(ngModel)]="newSchedule.recipients" placeholder="email1@co.com, email2@co.com">
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button type="button" class="btn btn-sm btn-primary" (click)="createSchedule()" [disabled]="!newSchedule.report_key">
              <i class="bi bi-check-lg me-1"></i>{{ 'common.create' | translate }}
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="showForm=false">{{ 'common.cancel' | translate }}</button>
          </div>
        </section>

        <!-- Schedules List -->
        <section class="ui-section ui-section--flush">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">{{ 'reports.scheduled' | translate }}</h2></div>
          </div>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
              <tr><th class="ps-4">{{ 'reports.report_label' | translate }}</th><th>{{ 'reports.frequency' | translate }}</th><th>{{ 'reports.format' | translate }}</th><th>{{ 'reports.recipients' | translate }}</th><th>{{ 'reports.last_run' | translate }}</th><th>{{ 'common.status' | translate }}</th><th class="pe-4">{{ 'common.actions' | translate }}</th></tr>
              </thead>
              <tbody>
              <tr *ngFor="let s of schedules">
                <td class="ps-4 fw-medium">{{ getReportName(s.report_key) }}</td>
                <td><span class="ui-pill ui-pill--brand">{{ s.frequency }}</span></td>
                <td>{{ s.export_format | uppercase }}</td>
                <td class="small ui-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ s.recipients }}</td>
                <td class="small ui-muted">{{ s.last_run_at | date:'short' }}</td>
                <td>
                  <span class="ui-pill" [ngClass]="s.last_run_status==='success'?'ui-pill--success':s.last_run_status==='error'?'ui-pill--danger':'ui-pill--warning'">
                    <span class="ui-pill__dot"></span>{{ s.last_run_status || ('common.pending' | translate) }}
                  </span>
                </td>
                <td class="pe-4">
                  <button type="button" class="btn btn-sm btn-outline-danger" (click)="remove(s)" [title]="'common.delete' | translate"><i class="bi bi-trash"></i></button>
                </td>
              </tr>
              </tbody>
            </table>
          </div>
          <div *ngIf="!schedules.length" class="p-4">
            <div class="ui-state">
              <span class="ui-state__icon"><i class="bi bi-calendar-x"></i></span>
              <p>{{ 'reports.no_schedules' | translate }}</p>
            </div>
          </div>
        </section>
      </div>
    </main>
  `
})
export class ReportSchedulerComponent implements OnInit {
  schedules: ScheduledReport[] = [];
  reportOptions: ReportCatalogItem[] = [];
  showForm = false;
  newSchedule: Partial<ScheduledReport> = { frequency: 'weekly', export_format: 'csv', recipients: '' };

  constructor(private svc: ReportService, private i18n: I18nService, private dialog: ConfirmDialogService) {}

  ngOnInit(): void {
    this.load();
    this.svc.getCatalog().subscribe(items => this.reportOptions = items.filter(i => i.exportable));
  }

  private load(): void { this.svc.getScheduledReports().subscribe(d => this.schedules = d); }

  createSchedule(): void {
    this.svc.createScheduledReport(this.newSchedule).subscribe(() => {
      this.showForm = false;
      this.newSchedule = { frequency: 'weekly', export_format: 'csv', recipients: '' };
      this.load();
    });
  }

  async remove(s: ScheduledReport): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('reports.confirm_delete_schedule'), { type: 'danger' }))) return;
    this.svc.deleteScheduledReport(s.id).subscribe(() => this.load());
  }

  getReportName(key: string): string {
    return this.reportOptions.find(r => r.key === key)?.name || key.replace(/_/g, ' ');
  }
}
