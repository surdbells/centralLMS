import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import {Router, RouterLink} from '@angular/router';
import { ReportService } from '../../services/report.service';
import { ReportCategory, DashboardKPI } from '../../models/report.models';
import {AdminSkillsComponent} from '../../../admin/admin-skills-page/skills/admin-skills.component';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TuiIcon} from '@taiga-ui/core';
import {TranslatePipe} from '../../../translate.pipe';
import {I18nService} from '../../../i18n.service';

@Component({
  selector: 'app-reports-dashboard',
  standalone: true,
  imports: [CommonModule, RouterLink, AdminSkillsComponent, AdmintopComponent, TuiIcon, TranslatePipe],
  templateUrl: `reports-dashboard.component.html`,
  styles: [`
    .rd-grid { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
    .rd-link { text-decoration: none; }
    .rd-link .ui-card__body { gap: 6px; padding: 14px 16px; }
    .rd-cat { flex-direction: row; align-items: center; gap: 12px; }
    .rd-cat__icon { width: 38px; height: 38px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
      border-radius: 10px; background: rgba(var(--brand-primary-rgb), .08); color: var(--brand-primary); font-size: 1.05rem; }
    .rd-min { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .rd-cap { text-transform: capitalize; align-self: flex-start; }
    .rd-clamp { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  `]
})
export class ReportsDashboardComponent implements OnInit {
  categories: ReportCategory[] = [];
  kpis: DashboardKPI[] = [];
  quickAccess: any[] = [];
  savedReports: any[] = [];
  totalReports = 0;

  constructor(private reportService: ReportService,private router: Router, private i18n: I18nService) {}

  ngOnInit(): void {
    this.loadData();
  }

  private loadData(): void {
    // Categories. "My learning" (category `my`) never reaches this screen:
    // the API leaves it out of the catalogue and ReportService filters it too.
    this.reportService.getCategories().subscribe(cats => (this.categories = cats));

    // Total
    this.reportService.getCatalog().subscribe(items => {
      this.totalReports = items.length;
      const catNames = new Map(this.categories.map(c => [c.key, c.name]));
      // Quick access: top reports from key categories
      const popular = ['enrollment_overview', 'completion_rates', 'compliance_summary', 'assessment_scores_overview',
        'learner_satisfaction_nps', 'training_effectiveness_index', 'manager_team_dashboard', 'content_freshness'];
      this.quickAccess = popular
        .map(key => items.find(i => i.key === key))
        .filter(Boolean)
        .map(r => ({ ...r, catName: catNames.get(r!.category) ?? r!.category.replace(/_/g, ' ') }));
    });

    // KPIs — executive_dashboard's totals, which the API names in camelCase
    // (enrolments, completionRate, complianceRate, activeLast30, …).
    this.reportService.getDashboardSummary().subscribe({
      next: data => {
        const n = (v: unknown) => (Number.isFinite(Number(v)) ? Number(v) : 0);
        this.kpis = [
          { label: this.i18n.t('reports.kpi_total_enrollments'), value: n(data?.enrolments), icon: 'bi bi-person-plus', color: 'primary' },
          { label: this.i18n.t('reports.kpi_completion_rate'), value: n(data?.completionRate) + '%', icon: 'bi bi-check-circle', color: 'success' },
          { label: this.i18n.t('reports.kpi_compliance_rate'), value: n(data?.complianceRate) + '%', icon: 'bi bi-shield-check', color: 'info' },
          { label: this.i18n.t('reports.kpi_active_learners'), value: n(data?.activeLast30), icon: 'bi bi-people', color: 'warning' },
          { label: this.i18n.t('reports.kpi_completions'), value: n(data?.completed), icon: 'bi bi-trophy', color: 'success' },
          { label: this.i18n.t('reports.kpi_overdue'), value: n(data?.overdue), icon: 'bi bi-exclamation-triangle', color: 'danger' },
        ];
      },
      error: () => (this.kpis = []),
    });

    // Saved
    this.reportService.getSavedReports().subscribe(saved => this.savedReports = saved);
  }

  goBack() {
    void this.router.navigate(['/administration']);
  }
}
