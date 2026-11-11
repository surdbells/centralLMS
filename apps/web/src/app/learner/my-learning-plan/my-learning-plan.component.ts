import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslatePipe } from '../../translate.pipe';
import {
  LearningPlansService,
  type LearnerPlan,
  type PlanItem,
  type PlanCompletion,
  type PlanItemStatus,
} from '../../services/learning-plans.service';

/**
 * The learner's own Learning Plan: year tabs (2025 / 2026 / 2027 …), each with
 * the courses and interventions planned for them and their status — Not
 * Scheduled, Scheduled, In Progress, Completed. Course status comes from the
 * enrolment; a learner can set a target date, and mark a non-course
 * intervention done.
 */
@Component({
  selector: 'app-my-learning-plan',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe],
  templateUrl: './my-learning-plan.component.html',
  styleUrls: ['./my-learning-plan.component.scss'],
})
export class MyLearningPlanComponent implements OnInit {
  private readonly svc = inject(LearningPlansService);
  private readonly router = inject(Router);

  loading = true;
  failed = false;

  plans: LearnerPlan[] = [];
  completion: PlanCompletion | null = null;
  years: number[] = [];
  activeYear: number | null = null;

  ngOnInit(): void {
    this.load();
  }

  private load(): void {
    this.loading = true;
    this.failed = false;

    this.svc.mine().subscribe({
      next: ({ plans, completion }) => {
        this.plans = plans;
        this.completion = completion;
        this.years = [...new Set(plans.map((p) => p.year))].sort((a, b) => b - a);
        this.activeYear = this.years[0] ?? null;
        this.loading = false;
      },
      error: () => {
        this.failed = true;
        this.loading = false;
      },
    });
  }

  plansForYear(year: number): LearnerPlan[] {
    return this.plans.filter((p) => p.year === year);
  }

  yearCompletion(year: number): { total: number; completed: number; rate: number } | null {
    return this.completion?.byYear.find((b) => b.year === year) ?? null;
  }

  statusKey(status: PlanItemStatus): string {
    return 'plan.status_' + status;
  }

  setSchedule(item: PlanItem, raw: string): void {
    const date = raw && raw.trim() !== '' ? raw : null;
    item.scheduledDate = date;

    // Reflect the derived status immediately for a non-course / not-started item.
    if (item.status === 'not_scheduled' && date) {
      item.status = 'scheduled';
    } else if (item.status === 'scheduled' && !date) {
      item.status = 'not_scheduled';
    }

    this.svc.schedule(item.id, date).subscribe({ error: () => undefined });
  }

  markComplete(item: PlanItem): void {
    item.status = 'completed';
    this.svc.setMyItemStatus(item.id, 'completed').subscribe({ error: () => undefined });
  }

  startIntervention(item: PlanItem): void {
    item.status = 'in_progress';
    this.svc.setMyItemStatus(item.id, 'in_progress').subscribe({ error: () => undefined });
  }

  openCourse(item: PlanItem): void {
    if (item.courseId) {
      void this.router.navigate(['/player', item.courseId]);
    }
  }
}
