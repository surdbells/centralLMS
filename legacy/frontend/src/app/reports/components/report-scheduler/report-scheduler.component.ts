import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ReportService } from '../../services/report.service';
import { ScheduledReport, ReportCatalogItem } from '../../models/report.models';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';

@Component({
  selector: 'app-report-scheduler',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
    <div class="report-scheduler">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Scheduled Reports</h4>
        <div class="d-flex gap-2">
          <a routerLink="/admin/reports" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
          <button class="btn btn-primary btn-sm" (click)="showForm=!showForm"><i class="bi bi-plus-lg me-1"></i>New Schedule</button>
        </div>
      </div>

      <!-- New Schedule Form -->
      <div class="card border-0 shadow-sm mb-4" *ngIf="showForm">
        <div class="card-body">
          <h6 class="fw-semibold mb-3">Create Schedule</h6>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label small">Report</label>
              <select class="form-select form-select-sm" [(ngModel)]="newSchedule.report_key">
                <option value="">Select report...</option>
                <option *ngFor="let r of reportOptions" [value]="r.key">{{ r.name }}</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small">Frequency</label>
              <select class="form-select form-select-sm" [(ngModel)]="newSchedule.frequency">
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label small">Format</label>
              <select class="form-select form-select-sm" [(ngModel)]="newSchedule.export_format">
                <option value="csv">CSV</option>
                <option value="xlsx">Excel</option>
                <option value="pdf">PDF</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small">Recipients (emails)</label>
              <input type="text" class="form-control form-control-sm" [(ngModel)]="newSchedule.recipients" placeholder="email1@co.com, email2@co.com">
            </div>
          </div>
          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-sm btn-primary" (click)="createSchedule()" [disabled]="!newSchedule.report_key">
              <i class="bi bi-check-lg me-1"></i>Create
            </button>
            <button class="btn btn-sm btn-secondary" (click)="showForm=false">Cancel</button>
          </div>
        </div>
      </div>

      <!-- Schedules List -->
      <div class="card border-0 shadow-sm">
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
            <tr><th class="px-3">Report</th><th>Frequency</th><th>Format</th><th>Recipients</th><th>Last Run</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <tr *ngFor="let s of schedules">
              <td class="px-3 fw-medium">{{ getReportName(s.report_key) }}</td>
              <td><span class="badge bg-primary-subtle text-primary">{{ s.frequency }}</span></td>
              <td>{{ s.export_format | uppercase }}</td>
              <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ s.recipients }}</td>
              <td class="small text-muted">{{ s.last_run_at | date:'short' }}</td>
              <td>
                  <span class="badge" [class]="s.last_run_status==='success'?'bg-success-subtle text-success':s.last_run_status==='error'?'bg-danger-subtle text-danger':'bg-warning-subtle text-warning'">
                    {{ s.last_run_status || 'pending' }}
                  </span>
              </td>
              <td>
                <button class="btn btn-sm btn-outline-danger" (click)="remove(s)" title="Delete"><i class="bi bi-trash"></i></button>
              </td>
            </tr>
            <tr *ngIf="!schedules.length">
              <td colspan="7" class="text-center py-4 text-muted">No scheduled reports. Click "New Schedule" to get started.</td>
            </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
      </div>
    </main>
  `
})
export class ReportSchedulerComponent implements OnInit {
  schedules: ScheduledReport[] = [];
  reportOptions: ReportCatalogItem[] = [];
  showForm = false;
  newSchedule: Partial<ScheduledReport> = { frequency: 'weekly', export_format: 'csv', recipients: '' };

  constructor(private svc: ReportService) {}

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

  remove(s: ScheduledReport): void {
    if (!confirm('Delete this schedule?')) return;
    this.svc.deleteScheduledReport(s.id).subscribe(() => this.load());
  }

  getReportName(key: string): string {
    return this.reportOptions.find(r => r.key === key)?.name || key.replace(/_/g, ' ');
  }
}
