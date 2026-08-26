import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, forkJoin } from 'rxjs';
import { debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import {
  SkillsService,
  type CourseSkill,
  type CourseSkillAssignment,
  type LearnerSkill,
  type LearnerSkills,
  type Proficiency,
  type Skill,
  type SkillCategory,
  type SkillCourse,
  type SkillHolder,
  type SkillInput,
  type SkillStatistics,
} from '../../../services/skills.service';
import { UsersService, type User } from '../../../services/users.service';
import { CoursesService, type Course } from '../../../services/courses.service';
import { ApiError } from '../../../core/api';

type Tab = 'skills' | 'categories' | 'analytics';
type AnalyticsTab = 'overview' | 'top-skills' | 'skill-gaps' | 'course-skills' | 'user-lookup';

/**
 * Administering the skill dictionary: what skills exist, how they are filed,
 * which courses teach them, and who has ended up holding them.
 *
 * There is deliberately no way here to grant somebody a skill. A skill is held
 * by finishing a course that teaches it, and the API derives that from the
 * enrolments — so the only lever an administrator has is the mapping between
 * courses and skills. The legacy screen implied otherwise, and had two tables
 * behind it to store grants; both were empty and neither was ever read.
 */
@Component({
  selector: 'app-admin-skills',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './admin-skills.component.html',
  styleUrls: ['./admin-skills.component.scss'],
})
export class AdminSkillsComponent implements OnInit, OnDestroy {
  private readonly skillsService = inject(SkillsService);
  private readonly usersService = inject(UsersService);
  private readonly coursesService = inject(CoursesService);

  private readonly destroy$ = new Subject<void>();
  private readonly searchSubject = new Subject<string>();
  private readonly userSearchSubject = new Subject<string>();

  // --- data ---------------------------------------------------------------

  skills: Skill[] = [];
  categories: SkillCategory[] = [];

  /**
   * The counts, the untaught skills and the most widely held, in one call.
   *
   * This replaces four endpoints — summary, stats, gaps and top-skills — which
   * each ran their own count and did not agree: `total_skills` counted
   * inactive skills, `skill_stats` did not.
   */
  statistics: SkillStatistics | null = null;

  skillLearners: SkillHolder[] = [];
  skillCourses: SkillCourse[] = [];

  // Course-to-skill mapping
  courseSkills: CourseSkill[] = [];
  availableSkillsForCourse: Skill[] = [];
  selectedCourseId: string | null = null;
  selectedCourseName = '';
  courseSkillAssignments: CourseSkillAssignment[] = [];

  // Looking one person up
  users: User[] = [];
  targetUserId: string | null = null;
  selectedUserName = '';
  lookedUpSkills: LearnerSkills | null = null;
  userLookupLoading = false;
  usersLoading = false;
  userSearchTerm = '';

  courses: Course[] = [];
  coursesLoading = false;

  /** The last failure, shown rather than written to a console nobody reads. */
  error: string | null = null;

  // --- view state ---------------------------------------------------------

  activeTab: Tab = 'skills';
  analyticsSubTab: AnalyticsTab = 'overview';
  loading = true;
  saving = false;
  searchTerm = '';

  selectedCategoryId: string | null = null;
  selectedLevel: Proficiency | '' = '';
  showInactive = false;

  showSkillModal = false;
  showCategoryModal = false;
  showDeleteModal = false;
  showLearnersModal = false;
  showCoursesModal = false;
  showCourseSkillsModal = false;

  editingSkill: Partial<SkillInput> & { id?: string } = {};
  editingCategory: Partial<SkillCategory> = {};
  isEditMode = false;

  deleteType: 'skill' | 'category' = 'skill';
  deleteId: string | null = null;
  deleteName = '';

  selectedSkill: Skill | null = null;

  readonly colorPresets = [
    '#303234', '#17a2b8', '#28a745', '#dc3545', '#6f42c1',
    '#fd7e14', '#20c997', '#e83e8c', '#6c757d', '#343a40',
  ];

  readonly iconOptions = [
    'bi-star', 'bi-code-slash', 'bi-graph-up', 'bi-people',
    'bi-shield-check', 'bi-lightbulb', 'bi-chat-dots', 'bi-gear',
    'bi-briefcase', 'bi-book', 'bi-calculator', 'bi-clipboard-check',
    'bi-bar-chart', 'bi-diagram-3', 'bi-lock', 'bi-bullseye',
  ];

  readonly levels: Proficiency[] = ['beginner', 'intermediate', 'advanced', 'expert'];

  ngOnInit(): void {
    this.searchSubject
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(() => this.loadSkills());

    // The user picker asks the server rather than filtering an array it
    // happens to hold: the list is paged, so a client-side filter searched
    // only the first page and reported "no match" for people who exist.
    this.userSearchSubject
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe((term) => this.loadUsers(term));

    this.loadData();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- loading ------------------------------------------------------------

  loadData(): void {
    this.loading = true;
    this.error = null;

    forkJoin({
      statistics: this.skillsService.getStatistics(),
      categories: this.skillsService.getCategories(),
      skills: this.skillsService.getSkills(this.filters()),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ statistics, categories, skills }) => {
          this.statistics = statistics;
          this.categories = categories;
          this.skills = skills.items;
          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  loadSkills(): void {
    this.loading = true;

    this.skillsService
      .getSkills(this.filters())
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.skills = page.items;
          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  /**
   * The filters, as the server understands them.
   *
   * The narrowing happens in SQL. The old screen asked for everything and then
   * assigned `filteredSkills = this.skills` — a filter that filtered nothing,
   * over a list the server had already truncated to one page.
   */
  private filters(): { search?: string; categoryId?: string; level?: Proficiency; active?: boolean } {
    return {
      search: this.searchTerm.trim() || undefined,
      categoryId: this.selectedCategoryId ?? undefined,
      level: this.selectedLevel || undefined,
      active: this.showInactive ? undefined : true,
    };
  }

  onSearch(): void {
    this.searchSubject.next(this.searchTerm);
  }

  onFilterChange(): void {
    this.loadSkills();
  }

  onTabChange(tab: Tab): void {
    this.activeTab = tab;
  }

  onAnalyticsSubTabChange(subTab: AnalyticsTab): void {
    this.analyticsSubTab = subTab;

    if (subTab === 'user-lookup' && this.users.length === 0) {
      this.loadUsers();
    }

    if (subTab === 'course-skills' && this.courses.length === 0) {
      this.loadCourses();
    }
  }

  // --- skills -------------------------------------------------------------

  openNewSkillModal(): void {
    this.isEditMode = false;
    this.editingSkill = {
      name: '',
      description: '',
      categoryId: null,
      level: 'intermediate',
      icon: 'bi-star',
      colour: '#303234',
      isActive: true,
    };
    this.showSkillModal = true;
  }

  openEditSkillModal(skill: Skill): void {
    this.isEditMode = true;
    this.editingSkill = {
      id: skill.id,
      name: skill.name,
      description: skill.description,
      categoryId: skill.categoryId,
      level: skill.level,
      icon: skill.icon,
      colour: skill.colour,
      isActive: skill.isActive,
    };
    this.showSkillModal = true;
  }

  closeSkillModal(): void {
    this.showSkillModal = false;
    this.editingSkill = {};
  }

  saveSkill(): void {
    const name = this.editingSkill.name?.trim();

    if (!name) {
      return;
    }

    const { id, ...input } = this.editingSkill;
    const saved = this.isEditMode && id !== undefined
      ? this.skillsService.updateSkill(id, { ...input, name })
      : this.skillsService.createSkill({ ...input, name });

    this.saving = true;

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.closeSkillModal();
        this.loadData();
      },
      error: (error: ApiError) => {
        this.error = error.message;
        this.saving = false;
      },
    });
  }

  confirmDeleteSkill(skill: Skill): void {
    this.deleteType = 'skill';
    this.deleteId = skill.id;
    this.deleteName = skill.name;
    this.showDeleteModal = true;
  }

  // --- categories ---------------------------------------------------------

  openNewCategoryModal(): void {
    this.isEditMode = false;
    this.editingCategory = { name: '', description: '', colour: '#6c757d', isActive: true };
    this.showCategoryModal = true;
  }

  openEditCategoryModal(category: SkillCategory): void {
    this.isEditMode = true;
    this.editingCategory = { ...category };
    this.showCategoryModal = true;
  }

  closeCategoryModal(): void {
    this.showCategoryModal = false;
    this.editingCategory = {};
  }

  saveCategory(): void {
    const name = this.editingCategory.name?.trim();

    if (!name) {
      return;
    }

    const id = this.editingCategory.id;
    const saved = this.isEditMode && id !== undefined
      ? this.skillsService.updateCategory(id, { ...this.editingCategory, name })
      : this.skillsService.createCategory({ ...this.editingCategory, name });

    this.saving = true;

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.closeCategoryModal();
        this.loadData();
      },
      error: (error: ApiError) => {
        this.error = error.message;
        this.saving = false;
      },
    });
  }

  confirmDeleteCategory(category: SkillCategory): void {
    this.deleteType = 'category';
    this.deleteId = category.id;
    this.deleteName = category.name;
    this.showDeleteModal = true;
  }

  // --- deletion -----------------------------------------------------------

  closeDeleteModal(): void {
    this.showDeleteModal = false;
    this.deleteId = null;
    this.deleteName = '';
  }

  confirmDelete(): void {
    const id = this.deleteId;

    if (id === null) {
      return;
    }

    this.saving = true;

    const deleted = this.deleteType === 'skill'
      ? this.skillsService.deleteSkill(id)
      : this.skillsService.deleteCategory(id);

    deleted.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.closeDeleteModal();
        this.loadData();
      },
      error: (error: ApiError) => {
        this.error = error.message;
        this.saving = false;
      },
    });
  }

  // --- one skill ----------------------------------------------------------

  viewSkillLearners(skill: Skill): void {
    this.selectedSkill = skill;
    this.skillLearners = [];
    this.showLearnersModal = true;

    this.skillsService
      .getSkillLearners(skill.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => (this.skillLearners = page.items),
        error: (error: ApiError) => (this.error = error.message),
      });
  }

  closeLearnersModal(): void {
    this.showLearnersModal = false;
    this.selectedSkill = null;
    this.skillLearners = [];
  }

  viewSkillCourses(skill: Skill): void {
    this.selectedSkill = skill;
    this.skillCourses = [];
    this.showCoursesModal = true;

    this.skillsService
      .getSkillCourses(skill.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (courses) => (this.skillCourses = courses),
        error: (error: ApiError) => (this.error = error.message),
      });
  }

  closeCoursesModal(): void {
    this.showCoursesModal = false;
    this.selectedSkill = null;
    this.skillCourses = [];
  }

  // --- pickers ------------------------------------------------------------

  loadUsers(search?: string): void {
    this.usersLoading = true;

    this.usersService
      .options(search)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.users = page.items;
          this.usersLoading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.usersLoading = false;
        },
      });
  }

  onUserSearch(): void {
    this.userSearchSubject.next(this.userSearchTerm.trim());
  }

  loadCourses(): void {
    this.coursesLoading = true;

    this.coursesService
      .list({ perPage: 100 })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.courses = page.items;
          this.coursesLoading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.coursesLoading = false;
        },
      });
  }

  // --- what a course teaches ----------------------------------------------

  openCourseSkillsModal(course: Course): void {
    this.selectedCourseId = course.id;
    this.selectedCourseName = course.title;
    this.courseSkills = [];
    this.courseSkillAssignments = [];
    this.showCourseSkillsModal = true;

    forkJoin({
      assigned: this.skillsService.getCourseSkills(course.id),
      available: this.skillsService.getSkills({ active: true, perPage: 200 }),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ assigned, available }) => {
          this.courseSkills = assigned;
          this.courseSkillAssignments = assigned.map((cs) => ({
            skillId: cs.skillId,
            proficiency: cs.proficiency,
            isPrimary: cs.isPrimary,
          }));
          this.availableSkillsForCourse = available.items;
        },
        error: (error: ApiError) => (this.error = error.message),
      });
  }

  closeCourseSkillsModal(): void {
    this.showCourseSkillsModal = false;
    this.selectedCourseId = null;
    this.selectedCourseName = '';
    this.courseSkills = [];
    this.courseSkillAssignments = [];
  }

  addSkillToCourse(): void {
    this.courseSkillAssignments.push({
      skillId: '',
      proficiency: 'intermediate',
      // The first one carries the course, so it is the main one by default.
      isPrimary: this.courseSkillAssignments.length === 0,
    });
  }

  removeSkillFromCourse(index: number): void {
    this.courseSkillAssignments.splice(index, 1);

    if (this.courseSkillAssignments.length > 0 && !this.courseSkillAssignments.some((a) => a.isPrimary)) {
      this.courseSkillAssignments[0].isPrimary = true;
    }
  }

  setPrimarySkill(index: number): void {
    this.courseSkillAssignments.forEach((a, i) => (a.isPrimary = i === index));
  }

  saveCourseSkills(): void {
    const courseId = this.selectedCourseId;

    if (courseId === null) {
      return;
    }

    const chosen = this.courseSkillAssignments.filter((a) => a.skillId !== '');

    this.saving = true;

    this.skillsService
      .assignCourseSkills(courseId, chosen)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.closeCourseSkillsModal();
          // The counts on the dictionary move when a mapping changes.
          this.loadData();
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.saving = false;
        },
      });
  }

  isSkillAlreadyAssigned(skillId: string, currentIndex: number): boolean {
    return this.courseSkillAssignments.some((a, i) => i !== currentIndex && a.skillId === skillId);
  }

  getSkillNameById(skillId: string): string {
    return this.availableSkillsForCourse.find((s) => s.id === skillId)?.name ?? '';
  }

  // --- one person ---------------------------------------------------------

  onUserSelect(userId: string): void {
    const user = this.users.find((u) => u.id === userId);

    if (user === undefined) {
      return;
    }

    this.targetUserId = userId;
    this.selectedUserName = user.fullName;
    this.lookupUserSkills();
  }

  /**
   * One call where there were two.
   *
   * `user-skills` and `user-skill-progress` were separate endpoints that
   * disagreed about whether a half-finished course counted, so the screen
   * could show a skill as acquired in one panel and 60% complete in the other.
   */
  lookupUserSkills(): void {
    const userId = this.targetUserId;

    if (userId === null) {
      return;
    }

    this.userLookupLoading = true;
    this.lookedUpSkills = null;

    this.skillsService
      .getUserSkills(userId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (skills) => {
          this.lookedUpSkills = skills;
          this.userLookupLoading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.userLookupLoading = false;
        },
      });
  }

  clearUserLookup(): void {
    this.targetUserId = null;
    this.selectedUserName = '';
    this.lookedUpSkills = null;
    this.userSearchTerm = '';
    this.loadUsers();
  }

  get userAcquiredCount(): number {
    return this.lookedUpSkills?.totals.held ?? 0;
  }

  get userInProgressCount(): number {
    return this.lookedUpSkills?.totals.inProgress ?? 0;
  }

  get userAcquiredSkills(): LearnerSkill[] {
    return this.lookedUpSkills?.held ?? [];
  }

  get userInProgressSkills(): LearnerSkill[] {
    return this.lookedUpSkills?.inProgress ?? [];
  }

  // --- analytics ----------------------------------------------------------

  get topSkills(): SkillStatistics['mostHeld'] {
    return this.statistics?.mostHeld ?? [];
  }

  /** Skills the organisation has declared and has no way to give anybody. */
  get untaughtSkills(): SkillStatistics['untaught'] {
    return this.statistics?.untaught ?? [];
  }

  /**
   * How widely a skill is held, against the most widely held one.
   *
   * A share of the whole workforce would be the more interesting figure, but
   * this screen does not know the headcount and should not guess at it. The
   * legacy divided by `learners_with_skills`, which counted only people who
   * held at least one skill, so the leader always came out at 100%.
   */
  adoptionPercentage(learners: number): number {
    const most = this.topSkills[0]?.learners ?? 0;

    return most === 0 ? 0 : Math.round((learners / most) * 100);
  }

  // --- presentation -------------------------------------------------------

  getLevelBadgeClass(level: string): string {
    const classes: Record<string, string> = {
      beginner: 'bg-success',
      intermediate: 'bg-info',
      advanced: 'bg-warning text-dark',
      expert: 'bg-danger',
    };

    return classes[level] ?? 'bg-secondary';
  }

  getCategoryById(id: string | null): SkillCategory | undefined {
    return id === null ? undefined : this.categories.find((c) => c.id === id);
  }

  formatDate(date: string | null): string {
    return date === null
      ? '-'
      : new Date(date).toLocaleDateString('en-GB', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }

  trackBySkillId(_index: number, item: { skillId: string }): string {
    return item.skillId;
  }
}
