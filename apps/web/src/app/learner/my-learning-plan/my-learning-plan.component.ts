import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslatePipe } from '../../translate.pipe';
import {
  LearningPlansService,
  PLAN_CATEGORY_ORDER,
  type LearnerPlan,
  type PlanItem,
  type PlanCompletion,
  type PlanItemStatus,
} from '../../services/learning-plans.service';

/** A run of items under one category heading; `key` null = "General", undefined = no heading. */
export interface PlanItemGroup {
  key: string | null | undefined;
  items: PlanItem[];
}
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';

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
  imports: [CommonModule, FormsModule, TranslatePipe, SkeletonComponent],
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
        this.groups = new Map(plans.map((p) => [p.id, this.groupItems(p.items)]));
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

  /** Items of each plan, grouped by category (computed once per load). */
  private groups = new Map<string, PlanItemGroup[]>();

  groupsFor(plan: LearnerPlan): PlanItemGroup[] {
    return this.groups.get(plan.id) ?? [{ key: undefined, items: plan.items }];
  }

  /**
   * When any item carries a category, items go under category headings in
   * the fixed category order, with uncategorised (shared) items first under
   * "General". Otherwise the list stays flat, as before.
   */
  private groupItems(items: PlanItem[]): PlanItemGroup[] {
    if (!items.some((i) => !!i.category)) {
      return [{ key: undefined, items }];
    }
    const out: PlanItemGroup[] = [];
    const general = items.filter((i) => !i.category);
    if (general.length) {
      out.push({ key: null, items: general });
    }
    const known = new Set<string>(PLAN_CATEGORY_ORDER);
    for (const cat of PLAN_CATEGORY_ORDER) {
      const inCat = items.filter((i) => i.category === cat);
      if (inCat.length) {
        out.push({ key: cat, items: inCat });
      }
    }
    const unknown = items.filter((i) => !!i.category && !known.has(i.category));
    if (unknown.length) {
      out.push({ key: 'other', items: unknown });
    }
    return out;
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
    // The server coalesces a cleared date back to the item's planned start.
    item.scheduledDate = date ?? item.plannedStart ?? null;

    // Reflect the derived status immediately for a non-course / not-started item.
    if (item.status === 'not_scheduled' && item.scheduledDate) {
      item.status = 'scheduled';
    } else if (item.status === 'scheduled' && !item.scheduledDate) {
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
