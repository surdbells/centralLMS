import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, forkJoin } from 'rxjs';
import { debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import {
  SkillsService,
  type CourseSkillAssignment,
  type LearnerSkill,
  type Proficiency,
  type Skill as ApiSkill,
  type SkillCategory as ApiCategory,
  type SkillFilters,
} from '../../../services/skills.service';
import { UsersService, type User } from '../../../services/users.service';
import { CoursesService, type Course as ApiCourse } from '../../../services/courses.service';
import { LookupsService } from '../../../services/lookups.service';
import { ApiError } from '../../../core/api';

type Tab = 'skills' | 'categories' | 'analytics';
type AnalyticsTab = 'overview' | 'top-skills' | 'skill-gaps' | 'course-skills' | 'user-lookup';

/* The shapes this screen's template already reads. Each is filled in one
 * place — `adapt*()` below — so the markup keeps the names it was written
 * against and nothing else has to know the API speaks differently. */

export interface SkillRow {
  skill_id: string;
  name: string;
  description: string | null;
  level: Proficiency;
  icon: string | null;
  color: string;
  is_active: boolean;
  category_id: string | null;
  category_name: string | null;
  category_color: string | null;
  /** How many courses teach it. Counted at read time, never stored. */
  course_count: number;
  learners_acquired: number;
  learners_in_progress: number;
  created_at: string | null;
  /** Of everybody in the organisation, the share who hold it. */
  adoption_rate: number;
  total_users: number;
}

export interface CategoryRow {
  category_id: string;
  name: string;
  description: string | null;
  color: string;
  is_active: boolean;
  skill_count: number;
  created_at: string | null;
}

export interface TopSkillRow {
  skill_id: string;
  name: string;
  color: string;
  icon: string | null;
  category_name: string | null;
  learners_acquired: number;
}

export interface LearnerRow {
  user_id: string;
  full_name: string | null;
  first_name: string;
  last_name: string;
  email: string;
  profile_image: string | null;
  courses_completed: number;
  highest_proficiency: Proficiency;
  skill_acquired_at: string | null;
}

export interface CourseRow {
  course_id: string;
  title: string;
  category_name: string | null;
  duration_minutes: number | null;
  thumbnail_url: string | null;
  proficiency_level: Proficiency;
  is_primary: boolean;
}

export interface UserRow {
  user_id: string;
  first_name: string;
  last_name: string;
  email: string;
  profile_image: string | null;
}

/** One row of somebody's skill progress, held or otherwise. */
export interface ProgressRow {
  skill_name: string;
  category_name: string | null;
  icon: string | null;
  color: string;
  is_acquired: boolean;
  highest_proficiency: Proficiency;
  completed_courses: number;
  in_progress_courses: number;
  total_courses: number;
  progress_percentage: number;
}

export interface SkillDraft {
  name: string;
  description: string;
  category_id: string | null;
  level: Proficiency;
  icon: string;
  color: string;
  is_active: boolean;
  skill_id?: string;
}

export interface CategoryDraft {
  name: string;
  description: string;
  color: string;
  is_active: boolean;
  category_id?: string;
}

export interface AssignmentRow {
  skill_id: string;
  proficiency_level: Proficiency;
  is_primary: boolean;
}

/**
 * Administering the skill dictionary: what skills exist, how they are filed,
 * which courses teach them, and who has ended up holding them.
 *
 * There is deliberately no way here to grant somebody a skill. A skill is held
 * by finishing a course that teaches it, and the API derives that from the
 * enrolments — so the only lever an administrator has is the mapping between
 * courses and skills. The legacy screen implied otherwise, and had two tables
 * behind it to store grants; both were empty and neither was ever read.
 *
 * The analytics tab is the part that changed most underneath. Every figure on
 * it used to come from a dedicated endpoint that recomputed the same counts a
 * slightly different way — "acquired" meant one thing on the adoption table
 * and another on the gap table. There is one skill list now, counted once, and
 * the two tables are two orderings of it.
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
  private readonly lookupsService = inject(LookupsService);

  private readonly destroy$ = new Subject<void>();
  private readonly searchSubject = new Subject<string>();
  private readonly userSearchSubject = new Subject<string>();

  // --- what the screen shows ----------------------------------------------

  filteredSkills: SkillRow[] = [];
  categories: CategoryRow[] = [];

  summary = {
    total_skills: 0,
    active_skills: 0,
    total_categories: 0,
    courses_with_skills: 0,
    learners_with_skills: 0,
  };

  topSkills: TopSkillRow[] = [];

  skillLearners: LearnerRow[] = [];
  skillCourses: CourseRow[] = [];

  availableSkillsForCourse: SkillRow[] = [];
  courseSkillAssignments: AssignmentRow[] = [];
  selectedCourseName = '';
  private selectedCourseId: string | null = null;

  courses: CourseRow[] = [];
  coursesLoading = false;

  filteredUsers: UserRow[] = [];
  usersLoading = false;
  userSearchTerm = '';
  targetUserId: string | null = null;
  selectedUserName = '';
  userLookupLoading = false;
  userSkills: ProgressRow[] = [];
  userSkillProgress: ProgressRow[] = [];

  error: string | null = null;

  // --- state --------------------------------------------------------------

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
  showSkillDetailModal = false;
  showCategoryDetailModal = false;

  isEditMode = false;
  editingSkill: SkillDraft = this.blankSkill();
  editingCategory: CategoryDraft = this.blankCategory();

  selectedSkill: SkillRow | null = null;
  selectedSkillDetail: SkillRow | null = null;
  selectedCategoryDetail: CategoryRow | null = null;

  deleteName = '';
  private deleteType: 'skill' | 'category' = 'skill';
  private deleteId: string | null = null;

  readonly levels: Proficiency[] = ['beginner', 'intermediate', 'advanced', 'expert'];

  readonly iconOptions = [
    'bi-star', 'bi-lightbulb', 'bi-code-slash', 'bi-graph-up', 'bi-people',
    'bi-briefcase', 'bi-gear', 'bi-shield-check', 'bi-chat-dots', 'bi-palette',
    'bi-calculator', 'bi-globe', 'bi-book', 'bi-trophy', 'bi-rocket',
  ];

  readonly colorPresets = [
    '#303234', '#c9a227', '#4e79a7', '#59a14f', '#e15759',
    '#f28e2b', '#76b7b2', '#b07aa1', '#9c755f', '#bab0ac',
  ];

  /**
   * How many people are in the organisation.
   *
   * The denominator every adoption figure on this screen divides by. Read once
   * with the rest; the legacy re-counted it inside each analytics endpoint,
   * with three different ideas of whether a suspended account counted.
   */
  private totalUsers = 0;

  /** Every skill, unfiltered, for the analytics tables and the course picker. */
  private allSkills: SkillRow[] = [];

  /**
   * Course category names by id.
   *
   * A course carries the id; the name lives in the lookup. Read once here
   * rather than joined onto every course row, because the same six names
   * would otherwise come back on every course in the list.
   */
  private courseCategories = new Map<string, string>();

  ngOnInit(): void {
    this.searchSubject
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(() => this.loadSkills());

    this.userSearchSubject
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(() => this.loadUsers());

    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // -------------------------------------------------------------------------
  // Loading
  // -------------------------------------------------------------------------

  private load(): void {
    this.loading = true;

    forkJoin({
      statistics: this.skillsService.getStatistics(),
      categories: this.skillsService.getCategories(),
      skills: this.skillsService.getSkills({ perPage: 500 }),
      users: this.usersService.statistics(),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ statistics, categories, skills, users }) => {
          this.totalUsers = users.total;

          this.summary = {
            total_skills: statistics.skills,
            active_skills: statistics.activeSkills,
            total_categories: statistics.categories,
            courses_with_skills: statistics.coursesMapped,
            learners_with_skills: statistics.learnersWithSkills,
          };

          this.categories = categories.map((c) => this.adaptCategory(c));
          this.allSkills = skills.items.map((s) => this.adaptSkill(s));

          // The icon and the category come from the skill already in hand,
          // not from a second shape of the same row.
          const byId = new Map(this.allSkills.map((s) => [s.skill_id, s]));

          this.topSkills = statistics.mostHeld.map((s) => ({
            skill_id: s.skillId,
            name: s.name,
            color: s.colour,
            icon: byId.get(s.skillId)?.icon ?? null,
            category_name: byId.get(s.skillId)?.category_name ?? null,
            learners_acquired: s.learners,
          }));
          this.filteredSkills = this.allSkills;
          this.availableSkillsForCourse = this.allSkills.filter((s) => s.is_active);

          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  private loadSkills(): void {
    this.loading = true;

    this.skillsService
      .getSkills(this.filters())
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.filteredSkills = page.items.map((s) => this.adaptSkill(s));
          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  /**
   * What the table is asking for.
   *
   * The filtering happens in the database. The legacy fetched every skill and
   * narrowed the array in the browser, which is why the search box got slower
   * the more skills an organisation declared.
   */
  private filters(): SkillFilters {
    return {
      search: this.searchTerm.trim() || undefined,
      categoryId: this.selectedCategoryId ?? undefined,
      level: this.selectedLevel || undefined,
      active: this.showInactive ? undefined : true,
      perPage: 500,
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

  onAnalyticsSubTabChange(tab: AnalyticsTab): void {
    this.analyticsSubTab = tab;

    if (tab === 'course-skills' && this.courses.length === 0) {
      this.loadCourses();
    }

    if (tab === 'user-lookup' && this.filteredUsers.length === 0) {
      this.loadUsers();
    }
  }

  // -------------------------------------------------------------------------
  // Translating between the API and what this template reads
  // -------------------------------------------------------------------------

  private adaptSkill(s: ApiSkill): SkillRow {
    return {
      skill_id: s.id,
      name: s.name,
      description: s.description,
      level: s.level,
      icon: s.icon,
      color: s.colour,
      is_active: s.isActive,
      category_id: s.categoryId,
      category_name: s.categoryName,
      category_color: s.categoryColour,
      course_count: s.courseCount,
      learners_acquired: s.learnerCount,
      learners_in_progress: s.learnersInProgress,
      created_at: s.createdAt,
      total_users: this.totalUsers,
      adoption_rate: this.totalUsers === 0
        ? 0
        : Math.round((s.learnerCount / this.totalUsers) * 100),
    };
  }

  private adaptCategory(c: ApiCategory): CategoryRow {
    return {
      category_id: c.id,
      name: c.name,
      description: c.description,
      color: c.colour,
      is_active: c.isActive,
      skill_count: c.skillCount,
      created_at: c.createdAt,
    };
  }

  private adaptCourse(c: ApiCourse): CourseRow {
    return {
      course_id: c.id,
      title: c.title,
      category_name: c.categoryId === null
        ? null
        : this.courseCategories.get(c.categoryId) ?? null,
      duration_minutes: c.durationMinutes,
      thumbnail_url: c.thumbnailUrl,
      proficiency_level: 'intermediate',
      is_primary: false,
    };
  }

  private adaptProgress(s: LearnerSkill): ProgressRow {
    return {
      skill_name: s.name,
      category_name: s.categoryName,
      icon: s.icon,
      color: s.colour,
      is_acquired: s.isHeld,
      highest_proficiency: s.proficiency,
      completed_courses: s.coursesCompleted,
      in_progress_courses: s.coursesInProgress,
      total_courses: s.coursesAvailable,
      progress_percentage: s.percentComplete,
    };
  }

  // -------------------------------------------------------------------------
  // The analytics tables
  // -------------------------------------------------------------------------

  /**
   * Every skill, most widely held first.
   *
   * Not a separate query: the same rows the dictionary lists, ordered.
   */
  get skillStats(): SkillRow[] {
    return [...this.allSkills].sort((a, b) => b.learners_acquired - a.learners_acquired);
  }

  /**
   * Where the organisation is thin — least adopted first.
   *
   * A skill nothing teaches sorts to the top, which is the useful reading: it
   * is not that people have not got round to it, it is that they cannot.
   */
  get skillGaps(): SkillRow[] {
    return [...this.allSkills].sort((a, b) => {
      if (a.course_count === 0 !== (b.course_count === 0)) {
        return a.course_count === 0 ? -1 : 1;
      }

      return a.adoption_rate - b.adoption_rate;
    });
  }

  getAdoptionPercentage(acquired: number): number {
    return this.totalUsers === 0 ? 0 : Math.round((acquired / this.totalUsers) * 100);
  }

  // -------------------------------------------------------------------------
  // Skills
  // -------------------------------------------------------------------------

  private blankSkill(): SkillDraft {
    return {
      name: '',
      description: '',
      category_id: null,
      level: 'intermediate',
      icon: 'bi-star',
      color: '#303234',
      is_active: true,
    };
  }

  openNewSkillModal(): void {
    this.isEditMode = false;
    this.editingSkill = this.blankSkill();
    this.showSkillModal = true;
  }

  openEditSkillModal(skill: SkillRow): void {
    this.isEditMode = true;
    this.editingSkill = {
      skill_id: skill.skill_id,
      name: skill.name,
      description: skill.description ?? '',
      category_id: skill.category_id,
      level: skill.level,
      icon: skill.icon ?? 'bi-star',
      color: skill.color,
      is_active: skill.is_active,
    };
    this.showSkillModal = true;
  }

  closeSkillModal(): void {
    this.showSkillModal = false;
  }

  saveSkill(): void {
    const draft = this.editingSkill;

    if (draft.name.trim() === '') {
      return;
    }

    this.saving = true;

    const input = {
      name: draft.name.trim(),
      description: draft.description.trim() || null,
      categoryId: draft.category_id,
      level: draft.level,
      icon: draft.icon,
      colour: draft.color,
      isActive: draft.is_active,
    };

    const request = draft.skill_id === undefined
      ? this.skillsService.createSkill(input)
      : this.skillsService.updateSkill(draft.skill_id, input);

    request.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.showSkillModal = false;
        this.load();
      },
      error: (error: ApiError) => {
        this.saving = false;
        this.error = Object.values(error.violations).flat()[0] ?? error.message;
      },
    });
  }

  confirmDeleteSkill(skill: SkillRow): void {
    this.deleteType = 'skill';
    this.deleteId = skill.skill_id;
    this.deleteName = skill.name;
    this.showDeleteModal = true;
  }

  confirmDeleteCategory(category: CategoryRow): void {
    this.deleteType = 'category';
    this.deleteId = category.category_id;
    this.deleteName = category.name;
    this.showDeleteModal = true;
  }

  closeDeleteModal(): void {
    this.showDeleteModal = false;
    this.deleteId = null;
  }

  confirmDelete(): void {
    const id = this.deleteId;

    if (id === null) {
      return;
    }

    this.saving = true;

    const request = this.deleteType === 'skill'
      ? this.skillsService.deleteSkill(id)
      : this.skillsService.deleteCategory(id);

    request.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.closeDeleteModal();
        this.load();
      },
      error: (error: ApiError) => {
        this.saving = false;
        this.closeDeleteModal();
        this.error = error.message;
      },
    });
  }

  // -------------------------------------------------------------------------
  // Categories
  // -------------------------------------------------------------------------

  private blankCategory(): CategoryDraft {
    return { name: '', description: '', color: '#303234', is_active: true };
  }

  openNewCategoryModal(): void {
    this.isEditMode = false;
    this.editingCategory = this.blankCategory();
    this.showCategoryModal = true;
  }

  openEditCategoryModal(category: CategoryRow): void {
    this.isEditMode = true;
    this.editingCategory = {
      category_id: category.category_id,
      name: category.name,
      description: category.description ?? '',
      color: category.color,
      is_active: category.is_active,
    };
    this.showCategoryModal = true;
  }

  closeCategoryModal(): void {
    this.showCategoryModal = false;
  }

  saveCategory(): void {
    const draft = this.editingCategory;

    if (draft.name.trim() === '') {
      return;
    }

    this.saving = true;

    const input = {
      name: draft.name.trim(),
      description: draft.description.trim() || null,
      colour: draft.color,
      isActive: draft.is_active,
    };

    const request = draft.category_id === undefined
      ? this.skillsService.createCategory(input)
      : this.skillsService.updateCategory(draft.category_id, input);

    request.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.showCategoryModal = false;
        this.load();
      },
      error: (error: ApiError) => {
        this.saving = false;
        this.error = Object.values(error.violations).flat()[0] ?? error.message;
      },
    });
  }

  // -------------------------------------------------------------------------
  // Looking at one skill
  // -------------------------------------------------------------------------

  viewSkillDetail(skillId: string): void {
    this.selectedSkillDetail = this.allSkills.find((s) => s.skill_id === skillId) ?? null;
    this.showSkillDetailModal = this.selectedSkillDetail !== null;
  }

  closeSkillDetailModal(): void {
    this.showSkillDetailModal = false;
  }

  viewCategoryDetail(categoryId: string): void {
    this.selectedCategoryDetail = this.categories.find((c) => c.category_id === categoryId) ?? null;
    this.showCategoryDetailModal = this.selectedCategoryDetail !== null;
  }

  closeCategoryDetailModal(): void {
    this.showCategoryDetailModal = false;
  }

  /** Who holds it, and from finishing what. */
  viewSkillLearners(skill: SkillRow): void {
    this.selectedSkill = skill;
    this.skillLearners = [];
    this.showLearnersModal = true;

    this.skillsService
      .getSkillLearners(skill.skill_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.skillLearners = page.items.map((h) => {
            const parts = (h.fullName ?? '').split(' ');

            return {
              user_id: h.userId,
              full_name: h.fullName,
              first_name: parts[0] ?? '',
              last_name: parts.slice(1).join(' '),
              email: h.email,
              profile_image: h.avatarUrl,
              courses_completed: h.courses,
              highest_proficiency: h.proficiency,
              skill_acquired_at: h.acquiredAt,
            };
          });
        },
        error: (error: ApiError) => (this.error = error.message),
      });
  }

  closeLearnersModal(): void {
    this.showLearnersModal = false;
  }

  viewSkillCourses(skill: SkillRow): void {
    this.selectedSkill = skill;
    this.skillCourses = [];
    this.showCoursesModal = true;

    this.skillsService
      .getSkillCourses(skill.skill_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (courses) => {
          this.skillCourses = courses.map((c) => ({
            course_id: c.courseId,
            title: c.title,
            category_name: null,
            duration_minutes: c.durationMinutes,
            thumbnail_url: c.thumbnailUrl,
            proficiency_level: c.proficiency,
            is_primary: c.isPrimary,
          }));
        },
        error: (error: ApiError) => (this.error = error.message),
      });
  }

  closeCoursesModal(): void {
    this.showCoursesModal = false;
  }

  // -------------------------------------------------------------------------
  // What a course teaches
  // -------------------------------------------------------------------------

  loadCourses(): void {
    this.coursesLoading = true;

    forkJoin({
      courses: this.coursesService.list({ perPage: 200 }),
      categories: this.lookupsService.list('category'),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ courses, categories }) => {
          this.courseCategories = new Map(categories.map((c) => [c.id, c.name]));
          this.courses = courses.items.map((c) => this.adaptCourse(c));
          this.coursesLoading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.coursesLoading = false;
        },
      });
  }

  openCourseSkillsModal(course: CourseRow): void {
    this.selectedCourseId = course.course_id;
    this.selectedCourseName = course.title;
    this.courseSkillAssignments = [];
    this.showCourseSkillsModal = true;

    this.skillsService
      .getCourseSkills(course.course_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (assigned) => {
          this.courseSkillAssignments = assigned.map((cs) => ({
            skill_id: cs.skillId,
            proficiency_level: cs.proficiency,
            is_primary: cs.isPrimary,
          }));
        },
        error: (error: ApiError) => (this.error = error.message),
      });
  }

  closeCourseSkillsModal(): void {
    this.showCourseSkillsModal = false;
    this.selectedCourseId = null;
  }

  addSkillToCourse(): void {
    this.courseSkillAssignments = [
      ...this.courseSkillAssignments,
      {
        skill_id: '',
        proficiency_level: 'intermediate',
        // The first skill added is the main one, because a course that teaches
        // something has to teach one thing chiefly.
        is_primary: this.courseSkillAssignments.length === 0,
      },
    ];
  }

  removeSkillFromCourse(index: number): void {
    const removed = this.courseSkillAssignments[index];
    this.courseSkillAssignments = this.courseSkillAssignments.filter((_, i) => i !== index);

    // Removing the main one leaves the course without one, so the next takes over.
    if (removed?.is_primary && this.courseSkillAssignments.length > 0) {
      this.courseSkillAssignments[0].is_primary = true;
    }
  }

  /** Exactly one skill is the main one, so setting it clears the rest. */
  setPrimarySkill(index: number): void {
    this.courseSkillAssignments = this.courseSkillAssignments.map((a, i) => ({
      ...a,
      is_primary: i === index,
    }));
  }

  isSkillAlreadyAssigned(skillId: string, index: number): boolean {
    return this.courseSkillAssignments.some((a, i) => i !== index && a.skill_id === skillId);
  }

  saveCourseSkills(): void {
    const courseId = this.selectedCourseId;

    if (courseId === null) {
      return;
    }

    this.saving = true;

    // The whole set, in one call: this course teaches these things. The legacy
    // sent an add per row and a delete per removed row, so a failure part-way
    // left the course teaching half of what the screen was showing.
    const skills: CourseSkillAssignment[] = this.courseSkillAssignments
      .filter((a) => a.skill_id !== '')
      .map((a) => ({
        skillId: a.skill_id,
        proficiency: a.proficiency_level,
        isPrimary: a.is_primary,
      }));

    this.skillsService
      .assignCourseSkills(courseId, skills)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.closeCourseSkillsModal();
          this.load();
        },
        error: (error: ApiError) => {
          this.saving = false;
          this.error = Object.values(error.violations).flat()[0] ?? error.message;
        },
      });
  }

  // -------------------------------------------------------------------------
  // Looking somebody up
  // -------------------------------------------------------------------------

  onUserSearch(): void {
    this.userSearchSubject.next(this.userSearchTerm);
  }

  private loadUsers(): void {
    this.usersLoading = true;

    this.usersService
      .list({ search: this.userSearchTerm.trim() || undefined, perPage: 50 })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.filteredUsers = page.items.map((u: User) => ({
            user_id: u.id,
            first_name: u.firstName,
            last_name: u.lastName,
            email: u.email,
            profile_image: u.avatarUrl,
          }));
          this.usersLoading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.usersLoading = false;
        },
      });
  }

  onUserSelect(userId: string): void {
    const user = this.filteredUsers.find((u) => u.user_id === userId);

    if (user === undefined) {
      return;
    }

    this.targetUserId = userId;
    this.selectedUserName = `${user.first_name} ${user.last_name}`.trim();
    this.userLookupLoading = true;
    this.userSkills = [];
    this.userSkillProgress = [];

    // One call. The legacy asked `user-skills` and `user-skill-progress`
    // separately, and the two disagreed about whether an in-progress course
    // counted — so the same person could be shown as holding a skill in one
    // panel and part-way to it in the other.
    this.skillsService
      .getUserSkills(userId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (skills) => {
          this.userSkills = skills.held.map((s) => this.adaptProgress(s));
          this.userSkillProgress = [...skills.held, ...skills.inProgress]
            .map((s) => this.adaptProgress(s));
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
    this.userSkills = [];
    this.userSkillProgress = [];
  }

  getUserAcquiredCount(): number {
    return this.userSkills.length;
  }

  getUserInProgressCount(): number {
    return this.userSkillProgress.filter((p) => !p.is_acquired).length;
  }

  // -------------------------------------------------------------------------
  // Display
  // -------------------------------------------------------------------------

  getLevelBadgeClass(level: Proficiency | string): string {
    return ({
      beginner: 'bg-success',
      intermediate: 'bg-info',
      advanced: 'bg-warning text-dark',
      expert: 'bg-danger',
    } as Record<string, string>)[level] ?? 'bg-secondary';
  }

  formatDate(value: string | null): string {
    if (value === null || value === '') {
      return '-';
    }

    return new Date(value).toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  }

  trackBySkillId(_: number, skill: SkillRow): string {
    return skill.skill_id;
  }

  trackByCategoryId(_: number, category: CategoryRow): string {
    return category.category_id;
  }
}
