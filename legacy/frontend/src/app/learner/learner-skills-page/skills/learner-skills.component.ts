// skills/learner/learner-skills.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  SkillsService,
  LearnerSkill,
  LearnerSkillCategory,
  SkillProgress,
  SkillCourse
} from '../../../services/skills.service';

@Component({
  selector: 'app-learner-skills',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink],
  templateUrl: './learner-skills.component.html',
  styleUrls: ['./learner-skills.component.scss']
})
export class LearnerSkillsComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  // Data
  skills: LearnerSkill[] = [];
  skillsByCategory: LearnerSkillCategory[] = [];
  skillProgress: SkillProgress[] = [];
  skillCourses: SkillCourse[] = [];

  // UI State
  activeTab: 'acquired' | 'progress' | 'all' = 'acquired';
  loading = true;
  viewMode: 'grid' | 'list' = 'grid';
  selectedLevel: string = '';

  // Modal
  showCoursesModal = false;
  selectedSkill: SkillProgress | LearnerSkill | null = null;

  constructor(
    private skillsService: SkillsService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadData();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadData(): void {
    this.loading = true;

    // Load acquired skills
    this.skillsService.getMySkills()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.skills = data;
        },
        error: err => console.error('Error loading skills:', err)
      });

    // Load skills by category
    this.skillsService.getMySkillsByCategory()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.skillsByCategory = data;
        },
        error: err => console.error('Error loading skills by category:', err)
      });

    // Load skill progress
    this.skillsService.getMySkillProgress()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.skillProgress = data;
          this.loading = false;
        },
        error: err => {
          console.error('Error loading skill progress:', err);
          this.loading = false;
        }
      });
  }

  // ==========================================
  // GETTERS
  // ==========================================

  get acquiredSkills(): LearnerSkill[] {
    let filtered = this.skills;
    if (this.selectedLevel) {
      filtered = filtered.filter(s => s.highest_proficiency === this.selectedLevel);
    }
    return filtered;
  }

  get inProgressSkills(): SkillProgress[] {
    return this.skillProgress.filter(s => !s.is_acquired && s.in_progress_courses > 0);
  }

  get allSkillProgress(): SkillProgress[] {
    let filtered = this.skillProgress;
    if (this.selectedLevel) {
      filtered = filtered.filter(s => s.skill_level === this.selectedLevel);
    }
    return filtered;
  }

  get totalSkillsCount(): number {
    return this.skills.length;
  }

  get inProgressCount(): number {
    return this.inProgressSkills.length;
  }

  get expertSkillsCount(): number {
    return this.skills.filter(s => s.highest_proficiency === 'expert').length;
  }

  get categoryCount(): number {
    return this.skillsByCategory.length;
  }

  // ==========================================
  // SKILL COURSES
  // ==========================================

  viewSkillCourses(skill: SkillProgress | LearnerSkill): void {
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

  goToCourse(courseId: number): void {
    this.closeCoursesModal();
    this.router.navigate(['/player', courseId]);
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getLevelBadgeClass(level: string): string {
    const classes: Record<string, string> = {
      'beginner': 'badge-beginner',
      'intermediate': 'badge-intermediate',
      'advanced': 'badge-advanced',
      'expert': 'badge-expert'
    };
    return classes[level] || 'badge-secondary';
  }

  getProgressBarClass(percentage: number): string {
    if (percentage >= 100) return 'bg-success';
    if (percentage >= 50) return 'bg-info';
    if (percentage >= 25) return 'bg-warning';
    return 'bg-secondary';
  }

  formatDate(date: string | null): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  formatRelativeDate(date: string | null): string {
    if (!date) return '-';
    const d = new Date(date);
    const now = new Date();
    const diff = now.getTime() - d.getTime();
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));

    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    if (days < 7) return `${days} days ago`;
    if (days < 30) return `${Math.floor(days / 7)} weeks ago`;
    if (days < 365) return `${Math.floor(days / 30)} months ago`;
    return `${Math.floor(days / 365)} years ago`;
  }

  trackBySkillId(index: number, skill: LearnerSkill | SkillProgress): number {
    return skill.skill_id;
  }

  trackByCategoryName(index: number, category: LearnerSkillCategory): string {
    return category.category_name;
  }
}
