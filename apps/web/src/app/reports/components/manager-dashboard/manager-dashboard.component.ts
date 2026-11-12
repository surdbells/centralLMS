import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { RouterLink } from '@angular/router';
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
          <app-report-chart [data]="teamData" chartType="bar" [title]="'reports.team_compliance' | translate" [allowTypeChange]="true"></app-report-chart>
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
  kpis: any[] = [];
  teamMembers: any[] = [];
  actionItems: any[] = [];
  teamData: any = null;

  constructor(private svc: ReportService, private i18n: I18nService) {}

  ngOnInit(): void {
    this.svc.runReport('manager_team_dashboard', {}).subscribe(res => {
      const d = res.data || [];
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
      this.actionItems = (res.data || []).filter((i: any) => i.type === 'overdue' || i.days_overdue > 0);
    });
    this.svc.runReport('manager_compliance_summary', {}).subscribe(res => this.teamData = res);
  }
}
