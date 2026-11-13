import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { SelectComponent } from '../../shared/select/select.component';
import { ApiClient, Endpoints } from '../../core/api';
import { UsersService } from '../../services/users.service';
import {
  LearningPlansService,
  type LearningPlan,
  type AdminPlanItem,
} from '../../services/learning-plans.service';

interface UserOption { id: string; fullName: string; email: string; }

interface CourseOption { id: string; title: string; }

/**
 * Administering per-year learning plans: create a plan, fill it with courses or
 * interventions, assign people and publish it so they see it on their own
 * Learning Plan.
 */
@Component({
  selector: 'app-learning-plans',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe, SkeletonComponent, SelectComponent],
  templateUrl: './learning-plans.component.html',
  styleUrls: ['./learning-plans.component.scss'],
})
export class LearningPlansComponent implements OnInit {
  private readonly svc = inject(LearningPlansService);
  private readonly api = inject(ApiClient);
  private readonly i18n = inject(I18nService);
  private readonly usersService = inject(UsersService);

  loading = true;
  saving = false;
  message = '';
  error = '';

  plans: LearningPlan[] = [];
  courses: CourseOption[] = [];
  departments: { id: string; name: string }[] = [];
  assignDept = '';
  userSearch = '';
  userResults: UserOption[] = [];

  newPlan = { year: new Date().getFullYear(), title: '' };

  selected: LearningPlan | null = null;
  items: AdminPlanItem[] = [];
  newItem: { title: string; courseId: string; isMandatory: boolean } = { title: '', courseId: '', isMandatory: false };

  ngOnInit(): void {
    this.loadPlans();
    this.loadCourses();
    this.api.get<{ id: string; name: string }[]>(Endpoints.lookups.active('department')).subscribe({
      next: (rows) => (this.departments = rows.map((r) => ({ ...r, id: String(r.id) }))),
      error: () => undefined,
    });
  }

  private loadPlans(): void {
    this.loading = true;
    this.svc.list().subscribe({
      next: (plans) => { this.plans = plans; this.loading = false; },
      error: () => { this.error = this.i18n.t('plan.load_failed'); this.loading = false; },
    });
  }

  private loadCourses(): void {
    this.api.get<{ items?: CourseOption[] } | CourseOption[]>(Endpoints.courses.list, { perPage: 500 }).subscribe({
      next: (res) => {
        const list = Array.isArray(res) ? res : (res.items ?? []);
        this.courses = list.map((c) => ({ id: String(c.id), title: c.title }));
      },
      error: () => undefined,
    });
  }

  createPlan(): void {
    if (this.newPlan.title.trim() === '' || this.saving) {
      return;
    }
    this.saving = true;
    this.clear();
    this.svc.create({ year: this.newPlan.year, title: this.newPlan.title.trim() }).subscribe({
      next: (plan) => {
        this.plans = [plan, ...this.plans];
        this.newPlan.title = '';
        this.saving = false;
        this.select(plan);
      },
      error: () => { this.error = this.i18n.t('plan.save_failed'); this.saving = false; },
    });
  }

  select(plan: LearningPlan): void {
    this.selected = plan;
    this.items = [];
    this.svc.items(plan.id).subscribe({ next: (items) => (this.items = items), error: () => undefined });
  }

  closeEditor(): void {
    this.selected = null;
  }

  addItem(): void {
    const plan = this.selected;
    if (!plan || this.saving) {
      return;
    }
    const course = this.courses.find((c) => c.id === this.newItem.courseId);
    const title = this.newItem.title.trim() || course?.title || '';
    if (title === '') {
      return;
    }

    this.saving = true;
    this.clear();
    this.svc.saveItem(plan.id, {
      title,
      courseId: this.newItem.courseId || null,
      isMandatory: this.newItem.isMandatory,
      sortOrder: this.items.length,
    }).subscribe({
      next: (item) => {
        this.items = [...this.items, item];
        this.newItem = { title: '', courseId: '', isMandatory: false };
        this.saving = false;
      },
      error: () => { this.error = this.i18n.t('plan.save_failed'); this.saving = false; },
    });
  }

  removeItem(item: AdminPlanItem): void {
    const plan = this.selected;
    if (!plan) {
      return;
    }
    this.svc.deleteItem(plan.id, item.id).subscribe({
      next: () => (this.items = this.items.filter((i) => i.id !== item.id)),
      error: () => undefined,
    });
  }

  assignEveryone(): void {
    this.doAssign({ everyone: true });
  }

  assignDepartment(): void {
    if (this.assignDept) {
      this.doAssign({ departmentId: this.assignDept });
    }
  }

  searchUsers(): void {
    const term = this.userSearch.trim();
    if (term.length < 2) {
      this.userResults = [];
      return;
    }
    this.usersService.options(term, 10).subscribe({
      next: (res) => (this.userResults = (res.items ?? []).map((u) => ({ id: u.id, fullName: u.fullName, email: u.email }))),
      error: () => (this.userResults = []),
    });
  }

  assignUser(user: UserOption): void {
    this.userResults = [];
    this.userSearch = '';
    this.doAssign({ userIds: [user.id] });
  }

  private doAssign(body: { everyone?: boolean; departmentId?: string; userIds?: string[] }): void {
    const plan = this.selected;
    if (!plan) {
      return;
    }
    this.clear();
    this.svc.assign(plan.id, body).subscribe({
      next: (r) => { this.message = this.i18n.t('plan.assigned', { count: r.assigned }); this.refreshCounts(plan.id); },
      error: () => (this.error = this.i18n.t('plan.save_failed')),
    });
  }

  setStatus(plan: LearningPlan, status: 'draft' | 'published' | 'archived'): void {
    this.svc.setStatus(plan.id, status).subscribe({
      next: (updated) => {
        this.plans = this.plans.map((p) => (p.id === updated.id ? updated : p));
        if (this.selected?.id === updated.id) { this.selected = updated; }
      },
      error: () => (this.error = this.i18n.t('plan.save_failed')),
    });
  }

  async removePlan(plan: LearningPlan): Promise<void> {
    if (!confirm(this.i18n.t('plan.confirm_delete', { title: plan.title }))) {
      return;
    }
    this.svc.remove(plan.id).subscribe({
      next: () => {
        this.plans = this.plans.filter((p) => p.id !== plan.id);
        if (this.selected?.id === plan.id) { this.selected = null; }
      },
      error: () => (this.error = this.i18n.t('plan.save_failed')),
    });
  }

  private refreshCounts(id: string): void {
    this.svc.list().subscribe({
      next: (plans) => {
        this.plans = plans;
        this.selected = plans.find((p) => p.id === id) ?? this.selected;
      },
      error: () => undefined,
    });
  }

  private clear(): void {
    this.message = '';
    this.error = '';
  }
}
