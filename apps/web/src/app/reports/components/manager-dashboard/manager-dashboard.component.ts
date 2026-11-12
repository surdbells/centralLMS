import { Component, OnInit, inject } from '@angular/core';
import { CommonModule, DecimalPipe, Location } from '@angular/common';
import { RouterLink, Router } from '@angular/router';
import { ReportService } from '../../services/report.service';
import { ReportChartComponent } from '../report-chart/report-chart.component';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TranslatePipe} from '../../../translate.pipe';
import {I18nService} from '../../../i18n.service';

@Component({
  selector: 'app-manager-dashboard',
  standalone: true,
  imports: [CommonModule, RouterLink, ReportChartComponent, AdmintopComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
      <div class="ui-page manager-dashboard">
        <header class="ui-page-header">
          <button type="button" class="ui-page-header__back" (click)="goBack()" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></button>
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-people"></i></div>
          <div class="ui-page-header__titles">
            <h1>{{ 'reports.manager_dashboard' | translate }}</h1>
            <p>{{ 'reports.manager_subtitle' | translate }}</p>
          </div>
        </header>

        <!-- KPIs -->
        <div class="ui-kpi-grid ui-kpi-grid--4" *ngIf="kpis.length">
          <div *ngFor="let k of kpis" class="ui-kpi" [ngClass]="'ui-kpi--' + (k.color === 'primary' ? 'brand' : k.color)">
            <span class="ui-kpi__label">{{ k.label }}</span>
            <span class="ui-kpi__value">{{ k.value }}</span>
          </div>
        </div>

        <!-- Action Required -->
        <section class="ui-section" *ngIf="actionItems.length">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title"><i class="bi bi-exclamation-circle me-2 text-danger"></i>{{ 'reports.action_required' | translate }}</h2></div>
            <div class="ui-section__actions"><span class="ui-pill ui-pill--danger">{{ actionItems.length }}</span></div>
          </div>
          <ul class="ui-list">
            <li *ngFor="let item of actionItems.slice(0,5)" class="justify-content-between">
              <div class="min-w-0">
                <strong>{{ item.learner_name }}</strong>
                <span class="ui-muted mx-1">-</span>
                <span>{{ item.course_name || item.title }}</span>
              </div>
              <span class="ui-pill ui-pill--danger"><span class="ui-pill__dot"></span>{{ 'reports.days_overdue' | translate:{ count: item.days_overdue } }}</span>
            </li>
          </ul>
        </section>

        <!-- Team Chart -->
        <section class="ui-section" *ngIf="teamData">
          <app-report-chart [data]="teamData" chartType="doughnut" [title]="'reports.team_compliance' | translate" [allowTypeChange]="true"></app-report-chart>
        </section>

        <!-- Team Table -->
        <section class="ui-section ui-section--flush">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">{{ 'reports.team_members' | translate }}</h2></div>
          </div>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead>
                <tr><th class="ps-4">{{ 'common.name' | translate }}</th><th>{{ 'report.enrolled' | translate }}</th><th>{{ 'report.completed' | translate }}</th><th>{{ 'reports.overdue' | translate }}</th><th class="pe-4">{{ 'common.status' | translate }}</th></tr>
              </thead>
              <tbody>
                <tr *ngIf="!teamMembers.length">
                  <td colspan="5" class="ps-4 py-4 text-center ui-muted">{{ 'reports.no_team_members' | translate }}</td>
                </tr>
                <tr *ngFor="let m of teamMembers">
                  <td class="ps-4 fw-medium">{{ m.learner_name }}</td>
                  <td>{{ m.total_enrolled }}</td>
                  <td>{{ m.completed }}</td>
                  <td><span [class]="m.overdue>0?'text-danger fw-bold':'ui-muted'">{{ m.overdue }}</span></td>
                  <td class="pe-4"><span class="ui-pill" [ngClass]="m.status_badge==='danger'?'ui-pill--danger':m.status_badge==='warning'?'ui-pill--warning':'ui-pill--success'"><span class="ui-pill__dot"></span>{{ m.status }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  `
})
export class ManagerDashboardComponent implements OnInit {
  private readonly backLocation = inject(Location);
  private readonly navRouter = inject(Router);
  kpis: any[] = [];
  teamMembers: any[] = [];
  actionItems: any[] = [];
  teamData: any = null;

  constructor(private svc: ReportService, private i18n: I18nService) {}

  /** Header back button: the previous page, or /admin/reports when there is no history (fresh tab / deep link). */
  goBack(): void {
    if (window.history.length > 1) {
      this.backLocation.back();
      return;
    }
    void this.navRouter.navigate(['/admin/reports']);
  }

  ngOnInit(): void {
    // The API answers in camelCase (learner, enrolments, inProgress,
    // completionRate, daysOverdue …); rows are mapped to the names this
    // template reads.
    this.svc.runReport('manager_team_dashboard', { per_page: 500 }).subscribe(res => {
      const d = ((res.data || []) as any[]).map(m => this.toMember(m));
      this.teamMembers = d;
      const totEnr = d.reduce((s: number, m: any) => s + (m.total_enrolled || 0), 0);
      const totComp = d.reduce((s: number, m: any) => s + (m.completed || 0), 0);
      const totOvr = d.reduce((s: number, m: any) => s + (m.overdue || 0), 0);
      this.kpis = [
        { label: this.i18n.t('reports.kpi_team_members'), value: d.length, color: 'primary' },
        { label: this.i18n.t('reports.kpi_total_enrollments'), value: totEnr, color: 'info' },
        { label: this.i18n.t('reports.kpi_completions'), value: totComp, color: 'success' },
        { label: this.i18n.t('reports.kpi_overdue_items'), value: totOvr, color: 'danger' }
      ];
    });
    this.svc.runReport('manager_action_required', {}).subscribe(res => {
      this.actionItems = ((res.data || []) as any[])
        .map(i => ({
          learner_name: i.learner ?? i.learner_name ?? '',
          course_name: i.course ?? i.course_name ?? i.title ?? '',
          days_overdue: num(i.daysOverdue ?? i.days_overdue),
        }))
        .filter(i => i.days_overdue > 0);
    });
    // A summary report: totals only, no rows — so the chart is drawn from
    // them, as parts of the team's mandatory requirements.
    this.svc.runReport('manager_compliance_summary', {}).subscribe(res => {
      const t: any = res.summary || {};
      const completed = num(t.completed);
      const overdue = num(t.overdue);
      const pending = Math.max(0, num(t.pending) - overdue);
      this.teamData = {
        data: [
          { label: this.i18n.t('report.completed'), value: completed },
          { label: this.i18n.t('common.pending'), value: pending },
          { label: this.i18n.t('reports.overdue'), value: overdue },
        ],
      };
    });
  }

  private toMember(m: any): any {
    const overdue = num(m.overdue);
    const rate = num(m.completionRate ?? m.completion_rate);
    const badge = overdue > 0 ? 'danger' : rate < 50 && num(m.enrolments) > 0 ? 'warning' : 'success';
    return {
      learner_name: m.learner ?? m.learner_name ?? '',
      total_enrolled: num(m.enrolments ?? m.total_enrolled),
      completed: num(m.completed),
      in_progress: num(m.inProgress ?? m.in_progress),
      overdue,
      completion_rate: rate,
      status_badge: badge,
      status: this.i18n.t(badge === 'danger' ? 'reports.overdue' : badge === 'warning' ? 'reports.member_needs_attention' : 'reports.member_on_track'),
    };
  }
}

function num(v: unknown): number {
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
}
