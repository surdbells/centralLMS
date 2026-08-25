// skills/admin/admin-skills.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import {
  SkillsService,
  Skill,
  SkillCategory,
  SkillSummary,
  SkillLearner,
  SkillCourse,
  SkillStats,
  SkillGap,
  TopSkill,
  CourseSkill,
  SkillAssignment,
  LearnerSkill,
  SkillProgress,
  UserBasic,
  CourseBasic
} from '../../../services/skills.service';

@Component({
  selector: 'app-admin-skills',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './admin-skills.component.html',
  styleUrls: ['./admin-skills.component.scss']
})
export class AdminSkillsComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<string>();

  // Data
  skills: Skill[] = [];
  filteredSkills: Skill[] = [];
  categories: SkillCategory[] = [];
  summary: SkillSummary | null = null;
  skillLearners: SkillLearner[] = [];
  skillCourses: SkillCourse[] = [];

  // Analytics Data
  skillStats: SkillStats[] = [];
  skillGaps: SkillGap[] = [];
  topSkills: TopSkill[] = [];

  // Course Skills Data
  courseSkills: CourseSkill[] = [];
  availableSkillsForCourse: Skill[] = [];
  selectedCourseId: number | null = null;
  selectedCourseName: string = '';
  courseSkillAssignments: SkillAssignment[] = [];

  // User Skills Lookup
  users: UserBasic[] = [];
  filteredUsers: UserBasic[] = [];
  targetUserId: number | null = null;
  selectedUserName: string = '';
  userSkills: LearnerSkill[] = [];
  userSkillProgress: SkillProgress[] = [];
  userLookupLoading = false;
  usersLoading = false;
  userSearchTerm = '';

  // Courses for assignment
  courses: CourseBasic[] = [];
  coursesLoading = false;

  // UI State
  activeTab: 'skills' | 'categories' | 'analytics' = 'skills';
  analyticsSubTab: 'overview' | 'top-skills' | 'skill-gaps' | 'course-skills' | 'user-lookup' = 'overview';
  loading = true;
  saving = false;
  searchTerm = '';

  // Filters
  selectedCategoryId: number | null = null;
  selectedLevel: string = '';
  showInactive = false;

  // Modals
  showSkillModal = false;
  showCategoryModal = false;
  showDeleteModal = false;
  showLearnersModal = false;
  showCoursesModal = false;
  showCourseSkillsModal = false;
  showSkillDetailModal = false;
  showCategoryDetailModal = false;

  // Form data
  editingSkill: Partial<Skill> = {};
  editingCategory: Partial<SkillCategory> = {};
  isEditMode = false;
  deleteType: 'skill' | 'category' = 'skill';
  deleteId: number = 0;
  deleteName: string = '';

  // Selected skill for details
  selectedSkill: Skill | null = null;
  selectedSkillDetail: Skill | null = null;
  selectedCategoryDetail: SkillCategory | null = null;

  // Color presets
  colorPresets = [
    '#303234', '#17a2b8', '#28a745', '#dc3545', '#6f42c1',
    '#fd7e14', '#20c997', '#e83e8c', '#6c757d', '#343a40'
  ];

  // Icon options
  iconOptions = [
    'bi-star', 'bi-code-slash', 'bi-graph-up', 'bi-people',
    'bi-shield-check', 'bi-lightbulb', 'bi-chat-dots', 'bi-gear',
    'bi-briefcase', 'bi-book', 'bi-calculator', 'bi-clipboard-check',
    'bi-bar-chart', 'bi-diagram-3', 'bi-lock', 'bi-bullseye'
  ];

  levels = ['beginner', 'intermediate', 'advanced', 'expert'];

  constructor(private skillsService: SkillsService) {}

  ngOnInit(): void {
    this.loadData();
    this.setupSearch();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private setupSearch(): void {
    this.searchSubject.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      takeUntil(this.destroy$)
    ).subscribe(() => {
      this.filterSkills();
    });
  }

  loadData(): void {
    this.loading = true;

    // Load summary
    this.skillsService.getSkillsSummary()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => this.summary = data,
        error: err => console.error('Error loading summary:', err)
      });

    // Load categories
    this.skillsService.getCategories()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => this.categories = data,
        error: err => console.error('Error loading categories:', err)
      });

    // Load skills
    this.loadSkills();
  }

  loadSkills(): void {
    this.loading = true;
    this.skillsService.getSkills({
      active_only: !this.showInactive,
      category_id: this.selectedCategoryId || undefined,
      level: this.selectedLevel || undefined,
      search: this.searchTerm || undefined
    }).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.skills = data;
          this.filterSkills();
          this.loading = false;
        },
        error: err => {
          console.error('Error loading skills:', err);
          this.loading = false;
        }
      });
  }

  filterSkills(): void {
    this.filteredSkills = this.skills;
  }

  onSearch(): void {
    this.searchSubject.next(this.searchTerm);
    this.loadSkills();
  }

  onFilterChange(): void {
    this.loadSkills();
  }

  // ==========================================
  // SKILL CRUD
  // ==========================================

  openNewSkillModal(): void {
    this.isEditMode = false;
    this.editingSkill = {
      name: '',
      description: '',
      category_id: null,
      level: 'intermediate',
      icon: 'bi-star',
      color: '#303234',
      is_active: true
    };
    this.showSkillModal = true;
  }

  openEditSkillModal(skill: Skill): void {
    this.isEditMode = true;
    this.editingSkill = { ...skill };
    this.showSkillModal = true;
  }

  closeSkillModal(): void {
    this.showSkillModal = false;
    this.editingSkill = {};
  }

  saveSkill(): void {
    if (!this.editingSkill.name?.trim()) return;

    this.saving = true;

    if (this.isEditMode && this.editingSkill.skill_id) {
      this.skillsService.updateSkill(this.editingSkill.skill_id, this.editingSkill)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.saving = false;
            this.closeSkillModal();
            this.loadData();
          },
          error: err => {
            console.error('Error updating skill:', err);
            this.saving = false;
          }
        });
    } else {
      this.skillsService.createSkill(this.editingSkill)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.saving = false;
            this.closeSkillModal();
            this.loadData();
          },
          error: err => {
            console.error('Error creating skill:', err);
            this.saving = false;
          }
        });
    }
  }

  confirmDeleteSkill(skill: Skill): void {
    this.deleteType = 'skill';
    this.deleteId = skill.skill_id;
    this.deleteName = skill.name;
    this.showDeleteModal = true;
  }

  // ==========================================
  // CATEGORY CRUD
  // ==========================================

  openNewCategoryModal(): void {
    this.isEditMode = false;
    this.editingCategory = {
      name: '',
      description: '',
      color: '#6c757d',
      is_active: true
    };
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
    if (!this.editingCategory.name?.trim()) return;

    this.saving = true;

    if (this.isEditMode && this.editingCategory.category_id) {
      this.skillsService.updateCategory(this.editingCategory.category_id, this.editingCategory)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.saving = false;
            this.closeCategoryModal();
            this.loadData();
          },
          error: err => {
            console.error('Error updating category:', err);
            this.saving = false;
          }
        });
    } else {
      this.skillsService.createCategory(this.editingCategory)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.saving = false;
            this.closeCategoryModal();
            this.loadData();
          },
          error: err => {
            console.error('Error creating category:', err);
            this.saving = false;
          }
        });
    }
  }

  confirmDeleteCategory(category: SkillCategory): void {
    this.deleteType = 'category';
    this.deleteId = category.category_id;
    this.deleteName = category.name;
    this.showDeleteModal = true;
  }

  // ==========================================
  // DELETE CONFIRMATION
  // ==========================================

  closeDeleteModal(): void {
    this.showDeleteModal = false;
    this.deleteId = 0;
    this.deleteName = '';
  }

  confirmDelete(): void {
    this.saving = true;

    const deleteObs = this.deleteType === 'skill'
      ? this.skillsService.deleteSkill(this.deleteId)
      : this.skillsService.deleteCategory(this.deleteId);

    deleteObs.pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.closeDeleteModal();
          this.loadData();
        },
        error: err => {
          console.error('Error deleting:', err);
          this.saving = false;
        }
      });
  }

  // ==========================================
  // SKILL DETAILS
  // ==========================================

  viewSkillLearners(skill: Skill): void {
    this.selectedSkill = skill;
    this.skillLearners = [];
    this.showLearnersModal = true;

    this.skillsService.getSkillLearners(skill.skill_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => this.skillLearners = data,
        error: err => console.error('Error loading learners:', err)
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

    this.skillsService.getSkillCourses(skill.skill_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => this.skillCourses = data,
        error: err => console.error('Error loading courses:', err)
      });
  }

  closeCoursesModal(): void {
    this.showCoursesModal = false;
    this.selectedSkill = null;
    this.skillCourses = [];
  }

  // ==========================================
  // ANALYTICS
  // ==========================================

  loadAnalytics(): void {
    this.loading = true;

    forkJoin({
      stats: this.skillsService.getSkillStats(),
      gaps: this.skillsService.getSkillGaps(),
      top: this.skillsService.getTopSkills(10)
    }).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.skillStats = data.stats;
          this.skillGaps = data.gaps;
          this.topSkills = data.top;
          this.loading = false;
        },
        error: err => {
          console.error('Error loading analytics:', err);
          this.loading = false;
        }
      });
  }

  onTabChange(tab: 'skills' | 'categories' | 'analytics'): void {
    this.activeTab = tab;
    if (tab === 'analytics' && this.skillStats.length === 0) {
      this.loadAnalytics();
    }
  }

  onAnalyticsSubTabChange(subTab: 'overview' | 'top-skills' | 'skill-gaps' | 'course-skills' | 'user-lookup'): void {
    this.analyticsSubTab = subTab;
    if (subTab === 'user-lookup' && this.users.length === 0) {
      this.loadUsers();
    }
    if (subTab === 'course-skills' && this.courses.length === 0) {
      this.loadCourses();
    }
  }

  // Load users for dropdown
  loadUsers(search?: string): void {
    this.usersLoading = true;
    this.skillsService.getUsers(search)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.users = data;
          this.filteredUsers = data;
          this.usersLoading = false;
        },
        error: err => {
          console.error('Error loading users:', err);
          this.usersLoading = false;
        }
      });
  }

  // Filter users by search term
  onUserSearch(): void {
    if (!this.userSearchTerm.trim()) {
      this.filteredUsers = this.users;
      return;
    }
    const term = this.userSearchTerm.toLowerCase();
    this.filteredUsers = this.users.filter(u =>
      u.first_name.toLowerCase().includes(term) ||
      u.last_name.toLowerCase().includes(term) ||
      u.email.toLowerCase().includes(term)
    );
  }

  // Load courses for dropdown
  loadCourses(): void {
    if (this.courses.length > 0) return;
    this.coursesLoading = true;
    this.skillsService.getCourses()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.courses = data;
          this.coursesLoading = false;
        },
        error: err => {
          console.error('Error loading courses:', err);
          this.coursesLoading = false;
        }
      });
  }

  // Get percentage of total users who have this skill
  getAdoptionPercentage(learners: number): number {
    if (!this.summary || !this.summary.learners_with_skills) return 0;
    return Math.round((learners / this.summary.learners_with_skills) * 100);
  }

  // ==========================================
  // SKILL & CATEGORY DETAIL LOOKUP
  // ==========================================

  viewSkillDetail(skillId: number): void {
    this.skillsService.getSkill(skillId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.selectedSkillDetail = data;
          this.showSkillDetailModal = true;
        },
        error: err => console.error('Error loading skill detail:', err)
      });
  }

  closeSkillDetailModal(): void {
    this.showSkillDetailModal = false;
    this.selectedSkillDetail = null;
  }

  viewCategoryDetail(categoryId: number): void {
    this.skillsService.getCategory(categoryId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.selectedCategoryDetail = data;
          this.showCategoryDetailModal = true;
        },
        error: err => console.error('Error loading category:', err)
      });
  }

  closeCategoryDetailModal(): void {
    this.showCategoryDetailModal = false;
    this.selectedCategoryDetail = null;
  }

  // ==========================================
  // COURSE SKILLS ASSIGNMENT
  // ==========================================

  openCourseSkillsModal(course: CourseBasic): void {
    this.selectedCourseId = course.course_id;
    this.selectedCourseName = course.title;
    this.courseSkills = [];
    this.courseSkillAssignments = [];
    this.showCourseSkillsModal = true;

    // Load current course skills
    this.skillsService.getCourseSkills(course.course_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.courseSkills = data;
          // Convert to assignments format
          this.courseSkillAssignments = data.map(cs => ({
            skill_id: cs.skill_id,
            proficiency_level: cs.proficiency_level,
            is_primary: cs.is_primary
          }));
        },
        error: err => console.error('Error loading course skills:', err)
      });

    // Load all available skills for dropdown
    this.skillsService.getSkills({ active_only: true })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => this.availableSkillsForCourse = data,
        error: err => console.error('Error loading skills:', err)
      });
  }

  closeCourseSkillsModal(): void {
    this.showCourseSkillsModal = false;
    this.selectedCourseId = null;
    this.courseSkills = [];
    this.courseSkillAssignments = [];
  }

  addSkillToCourse(): void {
    this.courseSkillAssignments.push({
      skill_id: 0,
      proficiency_level: 'intermediate',
      is_primary: this.courseSkillAssignments.length === 0 // First one is primary
    });
  }

  removeSkillFromCourse(index: number): void {
    this.courseSkillAssignments.splice(index, 1);
    // If we removed the primary, make first one primary
    if (this.courseSkillAssignments.length > 0 && !this.courseSkillAssignments.some(a => a.is_primary)) {
      this.courseSkillAssignments[0].is_primary = true;
    }
  }

  setPrimarySkill(index: number): void {
    this.courseSkillAssignments.forEach((a, i) => a.is_primary = (i === index));
  }

  saveCourseSkills(): void {
    if (!this.selectedCourseId) return;

    // Filter out empty selections
    const validAssignments = this.courseSkillAssignments.filter(a => a.skill_id > 0);

    this.saving = true;
    this.skillsService.assignCourseSkills(this.selectedCourseId, validAssignments)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.closeCourseSkillsModal();
          this.loadSkills(); // Reload to update course counts
        },
        error: err => {
          console.error('Error saving course skills:', err);
          this.saving = false;
        }
      });
  }

  isSkillAlreadyAssigned(skillId: number, currentIndex: number): boolean {
    return this.courseSkillAssignments.some((a, i) => i !== currentIndex && a.skill_id === skillId);
  }

  getSkillNameById(skillId: number): string {
    const skill = this.availableSkillsForCourse.find(s => s.skill_id === skillId);
    return skill ? skill.name : '';
  }

  getCourseNameById(courseId: number): string {
    const course = this.courses.find(c => c.course_id === courseId);
    return course ? course.title : '';
  }

  // ==========================================
  // USER SKILLS LOOKUP
  // ==========================================

  onUserSelect(userId: number): void {
    this.targetUserId = userId;
    const user = this.users.find(u => u.user_id === userId);
    if (user) {
      this.selectedUserName = `${user.first_name} ${user.last_name}`;
      this.lookupUserSkills();
    }
  }

  lookupUserSkills(): void {
    if (!this.targetUserId) return;

    this.userLookupLoading = true;
    this.userSkills = [];
    this.userSkillProgress = [];

    forkJoin({
      skills: this.skillsService.getUserSkills(this.targetUserId),
      progress: this.skillsService.getUserSkillProgress(this.targetUserId)
    }).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.userSkills = data.skills;
          this.userSkillProgress = data.progress;
          this.userLookupLoading = false;
        },
        error: err => {
          console.error('Error looking up user skills:', err);
          this.userLookupLoading = false;
        }
      });
  }

  clearUserLookup(): void {
    this.targetUserId = null;
    this.selectedUserName = '';
    this.userSkills = [];
    this.userSkillProgress = [];
    this.userSearchTerm = '';
    this.filteredUsers = this.users;
  }

  getUserAcquiredCount(): number {
    return this.userSkillProgress.filter(sp => sp.is_acquired).length;
  }

  getUserInProgressCount(): number {
    return this.userSkillProgress.filter(sp => !sp.is_acquired && sp.in_progress_courses > 0).length;
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getLevelBadgeClass(level: string): string {
    const classes: Record<string, string> = {
      'beginner': 'bg-success',
      'intermediate': 'bg-info',
      'advanced': 'bg-warning text-dark',
      'expert': 'bg-danger'
    };
    return classes[level] || 'bg-secondary';
  }

  getCategoryById(id: number | null): SkillCategory | undefined {
    if (!id) return undefined;
    return this.categories.find(c => c.category_id === id);
  }

  formatDate(date: string | null): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  trackBySkillId(index: number, skill: Skill | SkillStats | SkillGap | TopSkill): number {
    return skill.skill_id;
  }

  trackByCategoryId(index: number, category: SkillCategory): number {
    return category.category_id;
  }
}
