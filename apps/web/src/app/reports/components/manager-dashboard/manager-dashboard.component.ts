import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { ReportService } from '../../services/report.service';
import { ReportChartComponent } from '../report-chart/report-chart.component';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';

@Component({
  selector: 'app-manager-dashboard',
  standalone: true,
  imports: [CommonModule, RouterLink, ReportChartComponent, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
    <div class="manager-dashboard">
      <h4 class="fw-bold mb-4"><i class="bi bi-people me-2"></i>Manager Dashboard</h4>

      <!-- KPIs -->
      <div class="row g-3 mb-4">
        <div *ngFor="let k of kpis" class="col-sm-6 col-md-3">
          <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-3">
              <h4 class="fw-bold mb-0" [class]="'text-'+k.color">{{ k.value }}</h4>
              <p class="text-muted small mb-0">{{ k.label }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Required -->
      <div class="card border-0 shadow-sm mb-4" *ngIf="actionItems.length">
        <div class="card-header bg-danger-subtle d-flex justify-content-between align-items-center">
          <h6 class="mb-0 text-danger fw-semibold"><i class="bi bi-exclamation-circle me-2"></i>Action Required</h6>
          <span class="badge bg-danger">{{ actionItems.length }}</span>
        </div>
        <div class="list-group list-group-flush">
          <div *ngFor="let item of actionItems.slice(0,5)" class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <strong>{{ item.learner_name }}</strong>
              <span class="text-muted mx-1">-</span>
              <span>{{ item.course_name || item.title }}</span>
            </div>
            <span class="badge bg-danger-subtle text-danger">{{ item.days_overdue }}d overdue</span>
          </div>
        </div>
      </div>

      <!-- Team Chart -->
      <div class="mb-4" *ngIf="teamData">
        <app-report-chart [data]="teamData" chartType="bar" title="Team Compliance" [allowTypeChange]="true"></app-report-chart>
      </div>

      <!-- Team Table -->
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h6 class="mb-0 fw-semibold">Team Members</h6></div>
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
              <tr><th class="px-3">Name</th><th>Enrolled</th><th>Completed</th><th>Overdue</th><th>Status</th></tr>
            </thead>
            <tbody>
              <tr *ngFor="let m of teamMembers">
                <td class="px-3 fw-medium">{{ m.learner_name }}</td>
                <td>{{ m.total_enrolled }}</td>
                <td>{{ m.completed }}</td>
                <td><span [class]="m.overdue>0?'text-danger fw-bold':'text-muted'">{{ m.overdue }}</span></td>
                <td><span class="badge" [class]="m.status_badge==='danger'?'bg-danger-subtle text-danger':m.status_badge==='warning'?'bg-warning-subtle text-warning':'bg-success-subtle text-success'">{{ m.status }}</span></td>
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
export class ManagerDashboardComponent implements OnInit {
  kpis: any[] = [];
  teamMembers: any[] = [];
  actionItems: any[] = [];
  teamData: any = null;

  constructor(private svc: ReportService) {}

  ngOnInit(): void {
    this.svc.runReport('manager_team_dashboard', {}).subscribe(res => {
      const d = res.data || [];
      this.teamMembers = d;
      const totEnr = d.reduce((s: number, m: any) => s + (m.total_enrolled || 0), 0);
      const totComp = d.reduce((s: number, m: any) => s + (m.completed || 0), 0);
      const totOvr = d.reduce((s: number, m: any) => s + (m.overdue || 0), 0);
      this.kpis = [
        { label: 'Team Members', value: d.length, color: 'primary' },
        { label: 'Total Enrollments', value: totEnr, color: 'info' },
        { label: 'Completions', value: totComp, color: 'success' },
        { label: 'Overdue Items', value: totOvr, color: 'danger' }
      ];
    });
    this.svc.runReport('manager_action_required', {}).subscribe(res => {
      this.actionItems = (res.data || []).filter((i: any) => i.type === 'overdue' || i.days_overdue > 0);
    });
    this.svc.runReport('manager_compliance_summary', {}).subscribe(res => this.teamData = res);
  }
}
