import { Component, OnDestroy, OnInit, inject } from '@angular/core';
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
import { AvatarComponent } from '../../shared/avatar/avatar.component';
import { PlanImportComponent } from './plan-import/plan-import.component';
import {
  LearningPlansService,
  PLAN_CATEGORY_ORDER,
  type LearningPlan,
  type AdminPlanItem,
  type PersonalItem,
  type PersonalItemsPage,
  type PersonalItemsPerson,
  type PlanCategory,
  type PlanItemStatus,
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
  imports: [
    CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe, SkeletonComponent, SelectComponent,
    AvatarComponent, PlanImportComponent,
  ],
  templateUrl: './learning-plans.component.html',
  styleUrls: ['./learning-plans.component.scss'],
})
export class LearningPlansComponent implements OnInit, OnDestroy {
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

  /** The Excel import wizard is open. */
  importing = false;

  // --- per-person items (imported plans) -------------------------------------

  editorTab: 'shared' | 'personal' = 'shared';
  memberOptions: { id: string; label: string }[] = [];
  personFilter: string | null = null;
  categoryFilter: PlanCategory | null = null;
  personalQuery = '';
  personalPage = 1;
  readonly personalPerPage = 10;
  personal: PersonalItemsPage | null = null;
  personalLoading = false;
  personalFailed = false;
  private personalSeq = 0;
  private searchTimer: ReturnType<typeof setTimeout> | null = null;

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
    const changed = this.selected?.id !== plan.id;
    this.selected = plan;
    this.items = [];
    this.svc.items(plan.id).subscribe({ next: (items) => (this.items = items), error: () => undefined });

    if (changed) {
      this.personFilter = null;
      this.categoryFilter = null;
      this.personalQuery = '';
      this.personalPage = 1;
      this.personal = null;
      this.categoryOptions = [];
      this.memberOptions = [];
      // An imported plan is mostly per-person items: open on those.
      this.editorTab = (plan.personalItemCount ?? 0) > 0 && plan.itemCount === 0 ? 'personal' : 'shared';
    }
    if (this.editorTab === 'personal') {
      this.loadPersonal();
    }
  }

  // --- per-person items ---------------------------------------------------------

  setEditorTab(tab: 'shared' | 'personal'): void {
    this.editorTab = tab;
    if (tab === 'personal' && this.personal === null && !this.personalLoading) {
      this.loadPersonal();
    }
  }

  ngOnDestroy(): void {
    if (this.searchTimer) { clearTimeout(this.searchTimer); }
  }

  private loadMembers(planId: string): void {
    this.svc.members(planId).subscribe({
      next: (res) => {
        if (this.selected?.id === planId) {
          this.memberOptions = (res.items ?? []).map((m) => ({ id: String(m.id), label: m.name ? `${m.name} · ${m.email}` : m.email }));
        }
      },
      error: () => undefined,
    });
  }

  loadPersonal(): void {
    const plan = this.selected;
    if (!plan) {
      return;
    }
    if (this.memberOptions.length === 0) {
      this.loadMembers(plan.id);
    }
    const seq = ++this.personalSeq;
    this.personalLoading = true;
    this.personalFailed = false;
    this.svc.personalItems(plan.id, {
      userId: this.personFilter,
      category: this.categoryFilter,
      q: this.personalQuery,
      page: this.personalPage,
      perPage: this.personalPerPage,
    }).subscribe({
      next: (page) => {
        if (seq !== this.personalSeq) { return; }
        this.personal = page;
        this.buildCategoryOptions();
        this.personalLoading = false;
      },
      error: () => {
        if (seq !== this.personalSeq) { return; }
        this.personalFailed = true;
        this.personalLoading = false;
      },
    });
  }

  onPersonFilter(id: string | null): void {
    this.personFilter = id || null;
    this.personalPage = 1;
    this.loadPersonal();
  }

  onCategoryFilter(cat: PlanCategory | null): void {
    this.categoryFilter = cat || null;
    this.personalPage = 1;
    this.loadPersonal();
  }

  onPersonalQuery(q: string): void {
    this.personalQuery = q;
    if (this.searchTimer) { clearTimeout(this.searchTimer); }
    this.searchTimer = setTimeout(() => {
      this.personalPage = 1;
      this.loadPersonal();
    }, 300);
  }

  goPersonalPage(page: number): void {
    if (page < 1 || page > this.personalPages || page === this.personalPage) {
      return;
    }
    this.personalPage = page;
    this.loadPersonal();
  }

  get personalPages(): number {
    const p = this.personal;
    return p ? Math.max(1, Math.ceil(p.total / (p.perPage || this.personalPerPage))) : 1;
  }

  /** Category picker options, labelled with the counts the server sent (kept stable for app-select). */
  categoryOptions: { value: PlanCategory; label: string }[] = [];

  private buildCategoryOptions(): void {
    const counts = this.personal?.categories ?? {};
    this.categoryOptions = PLAN_CATEGORY_ORDER
      .filter((c) => counts[c] !== undefined || this.categoryFilter === c)
      .map((c) => ({ value: c, label: `${this.i18n.t('plan.cat_' + c)} (${counts[c] ?? 0})` }));
  }

  statusPill(status: PlanItemStatus): string {
    switch (status) {
      case 'completed': return 'ui-pill--success';
      case 'in_progress': return 'ui-pill--info';
      case 'scheduled': return 'ui-pill--warning';
      default: return 'ui-pill--neutral';
    }
  }

  removePersonalItem(person: PersonalItemsPerson, item: PersonalItem): void {
    const plan = this.selected;
    if (!plan || !confirm(this.i18n.t('plan.confirm_delete_item', { title: item.title }))) {
      return;
    }
    this.svc.deleteItem(plan.id, item.id).subscribe({
      next: () => {
        person.items = person.items.filter((i) => i.id !== item.id);
        const page = this.personal;
        if (page && item.category && page.categories[item.category] !== undefined) {
          page.categories = { ...page.categories, [item.category]: Math.max(0, (page.categories[item.category] ?? 1) - 1) };
          this.buildCategoryOptions();
        }
        if (page && person.items.length === 0) {
          page.people = page.people.filter((p) => p !== person);
          page.total = Math.max(0, page.total - 1);
        }
        const count = Math.max(0, (plan.personalItemCount ?? 1) - 1);
        this.selected = { ...plan, personalItemCount: count };
        this.plans = this.plans.map((p) => (p.id === plan.id ? { ...p, personalItemCount: count } : p));
      },
      error: () => (this.error = this.i18n.t('plan.save_failed')),
    });
  }

  // --- Excel import ---------------------------------------------------------------

  openImport(): void {
    this.clear();
    this.importing = true;
  }

  closeImport(): void {
    this.importing = false;
  }

  /** The wizard wrote something: refresh counts (and the open editor). */
  onImported(): void {
    const openId = this.selected?.id ?? null;
    this.svc.list().subscribe({
      next: (plans) => {
        this.plans = plans;
        const again = openId ? plans.find((p) => p.id === openId) : undefined;
        if (again) {
          this.personal = null;
          // Import may have added members: force the person filter to reload.
          this.memberOptions = [];
          this.select(again);
        }
      },
      error: () => undefined,
    });
  }

  /** "Open plan" from the wizard's result list. */
  openImportedPlan(planId: string): void {
    this.importing = false;
    const show = (plans: LearningPlan[]): void => {
      const plan = plans.find((p) => p.id === planId);
      if (plan) {
        this.select(plan);
        if ((plan.personalItemCount ?? 0) > 0) {
          this.setEditorTab('personal');
        }
      }
    };
    if (this.plans.some((p) => p.id === planId)) {
      show(this.plans);
    } else {
      this.svc.list().subscribe({ next: (plans) => { this.plans = plans; show(plans); }, error: () => undefined });
    }
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
