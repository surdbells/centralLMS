import { Component, OnInit } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import {Router, RouterLink} from '@angular/router';
import { ReportService } from '../../services/report.service';
import { ReportCategory, DashboardKPI } from '../../models/report.models';
import {AdminSkillsComponent} from '../../../admin/admin-skills-page/skills/admin-skills.component';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TuiIcon} from '@taiga-ui/core';

@Component({
  selector: 'app-reports-dashboard',
  standalone: true,
  imports: [CommonModule, RouterLink, AdminSkillsComponent, AdmintopComponent, TuiIcon],
  templateUrl: `reports-dashboard.component.html`,
  styles: [`.cat-card:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; }`]
})
export class ReportsDashboardComponent implements OnInit {
  categories: ReportCategory[] = [];
  kpis: DashboardKPI[] = [];
  quickAccess: any[] = [];
  savedReports: any[] = [];
  totalReports = 0;

  constructor(private reportService: ReportService,private router: Router,) {}

  ngOnInit(): void {
    this.loadData();
  }

  private loadData(): void {
    // Categories
    this.reportService.getCategories().subscribe(cats => this.categories = cats);

    // Total
    this.reportService.getCatalog().subscribe(items => {
      this.totalReports = items.length;
      // Quick access: top reports from key categories
      const popular = ['enrollment_overview', 'completion_rates', 'compliance_summary', 'assessment_scores_overview',
        'learner_satisfaction_nps', 'training_effectiveness_index', 'manager_team_dashboard', 'content_freshness'];
      this.quickAccess = popular
        .map(key => items.find(i => i.key === key))
        .filter(Boolean)
        .map(r => ({ ...r, catName: r!.category.replace(/_/g, ' ') }));
    });

    // KPIs
    this.reportService.getDashboardSummary().subscribe(data => {
      this.kpis = [
        { label: 'Total Enrollments', value: data.enrollment.total_enrollments || 0, icon: 'bi bi-person-plus', color: 'primary', trend: 'up', change: data.enrollment_change },
        { label: 'Completion Rate', value: (data.completion.completion_rate || 0) + '%', icon: 'bi bi-check-circle', color: 'success', trend: data.completion_trend, change: data.completion_change },
        { label: 'Compliance Rate', value: (data.compliance.compliance_rate || 0) + '%', icon: 'bi bi-shield-check', color: 'info', trend: data.compliance_trend, change: data.compliance_change },
        { label: 'Active Learners', value: data.assessment.unique_learners || 0, icon: 'bi bi-people', color: 'warning' },
        { label: 'Avg Score', value: (data.assessment.avg_score || 0) + '%', icon: 'bi bi-trophy', color: 'success' },
        { label: 'Overdue', value: data.overdue_count || 0, icon: 'bi bi-exclamation-triangle', color: 'danger' }
      ];
    });

    // Saved
    this.reportService.getSavedReports().subscribe(saved => this.savedReports = saved);
  }

  goBack() {
    this.router.navigate(['/administration']).then(r => console.log(r));
  }
}
