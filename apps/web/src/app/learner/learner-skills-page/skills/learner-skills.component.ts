import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  SkillsService,
  type LearnerSkill,
  type LearnerSkills,
  type LearnerSkillsByCategory,
  type Proficiency,
  type SkillCourse,
} from '../../../services/skills.service';
import { ApiError } from '../../../core/api';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

/**
 * What this learner can do, and what they are on the way to being able to do.
 *
 * One call where there were three. `getMySkills`, `getMySkillsByCategory` and
 * `getMySkillProgress` were three requests whose answers had to agree and
 * sometimes did not — the first two counted a skill as held, the third decided
 * for itself. The API now answers all three at once, from one query.
 */
@Component({
  selector: 'app-learner-skills',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TranslatePipe],
  templateUrl: './learner-skills.component.html',
  styleUrls: ['./learner-skills.component.scss'],
})
export class LearnerSkillsComponent implements OnInit, OnDestroy {
  private readonly skillsService = inject(SkillsService);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private readonly destroy$ = new Subject<void>();

  mine: LearnerSkills = { held: [], inProgress: [], byCategory: [], totals: { held: 0, inProgress: 0 } };
  skillCourses: SkillCourse[] = [];

  activeTab: 'acquired' | 'progress' | 'all' = 'acquired';
  loading = true;
  error: string | null = null;
  viewMode: 'grid' | 'list' = 'grid';
  selectedLevel: Proficiency | '' = '';

  showCoursesModal = false;
  selectedSkill: LearnerSkill | null = null;

  ngOnInit(): void {
    this.loadData();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadData(): void {
    this.loading = true;
    this.error = null;

    this.skillsService
      .getMySkills()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (mine) => {
          this.mine = mine;
          this.loading = false;
        },
        error: (error: ApiError) => {
          // Said rather than logged. The three calls this replaced each wrote
          // to the console and left the spinner running.
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  // --- what to show -------------------------------------------------------

  get acquiredSkills(): LearnerSkill[] {
    return this.selectedLevel === ''
      ? this.mine.held
      : this.mine.held.filter((skill) => skill.proficiency === this.selectedLevel);
  }

  get inProgressSkills(): LearnerSkill[] {
    return this.mine.inProgress;
  }

  get allSkills(): LearnerSkill[] {
    const everything = [...this.mine.held, ...this.mine.inProgress];

    return this.selectedLevel === ''
      ? everything
      : everything.filter((skill) => skill.proficiency === this.selectedLevel);
  }

  get skillsByCategory(): LearnerSkillsByCategory[] {
    return this.mine.byCategory;
  }

  get totalSkillsCount(): number {
    return this.mine.totals.held;
  }

  get inProgressCount(): number {
    return this.mine.totals.inProgress;
  }

  get expertSkillsCount(): number {
    return this.mine.held.filter((skill) => skill.proficiency === 'expert').length;
  }

  get categoryCount(): number {
    return this.mine.byCategory.length;
  }

  // --- the courses behind a skill -----------------------------------------

  viewSkillCourses(skill: LearnerSkill): void {
    this.selectedSkill = skill;
    this.skillCourses = [];
    this.showCoursesModal = true;

    this.skillsService
      .getSkillCourses(skill.skillId)
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

  goToCourse(courseId: string): void {
    this.closeCoursesModal();
    void this.router.navigate(['/player', courseId]);
  }

  // --- presentation -------------------------------------------------------

  getLevelBadgeClass(level: string): string {
    const classes: Record<string, string> = {
      beginner: 'badge-beginner',
      intermediate: 'badge-intermediate',
      advanced: 'badge-advanced',
      expert: 'badge-expert',
    };

    return classes[level] ?? 'badge-secondary';
  }

  getProgressBarClass(percentage: number): string {
    if (percentage >= 100) {
      return 'bg-success';
    }

    if (percentage >= 50) {
      return 'bg-info';
    }

    if (percentage >= 25) {
      return 'bg-warning';
    }

    return 'bg-secondary';
  }

  formatDate(date: string | null): string {
    return date === null ? '-' : new Date(date).toLocaleDateString('en-GB', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  }

  formatRelativeDate(date: string | null): string {
    if (date === null) {
      return '-';
    }

    const days = Math.floor((Date.now() - new Date(date).getTime()) / (1000 * 60 * 60 * 24));

    if (days === 0) {
      return this.i18n.t('skills.today');
    }

    if (days === 1) {
      return this.i18n.t('skills.yesterday');
    }

    if (days < 7) {
      return this.i18n.t('skills.days_ago', { count: days });
    }

    if (days < 30) {
      return this.i18n.t('skills.weeks_ago', { count: Math.floor(days / 7) });
    }

    if (days < 365) {
      return this.i18n.t('skills.months_ago', { count: Math.floor(days / 30) });
    }

    return this.i18n.t('skills.years_ago', { count: Math.floor(days / 365) });
  }

  trackBySkillId(_index: number, skill: LearnerSkill): string {
    return skill.skillId;
  }

  trackByCategoryName(_index: number, category: LearnerSkillsByCategory): string {
    return category.categoryName;
  }
}
