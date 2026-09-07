import { Component, OnInit, OnDestroy, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterModule } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { LearningPathService } from '../../services/learning-path.service';
import {
  LearningPath,
  LearningPathCreateRequest,
  SkillLevel,
  PathType,
  CompletionType,
  Visibility,
  EnrollmentType,
  SKILL_LEVELS,
  PATH_STATUSES
} from '../../interfaces/learning-path.interfaces';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

@Component({
  selector: 'app-learning-path-form',
  standalone: true,
  imports: [CommonModule, FormsModule, ReactiveFormsModule, RouterModule, AdmintopComponent, TranslatePipe],
  templateUrl: './learning-path-form.component.html',
  styleUrls: ['./learning-path-form.component.scss']
})
export class LearningPathFormComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  // Mode
  mode: 'create' | 'edit' = 'create';
  pathId: string | null = null;

  // Data
  path = signal<LearningPath | null>(null);
  form!: FormGroup;

  // UI State
  loading = signal(true);
  saving = signal(false);
  activeTab = signal('basic');

  // Reference data
  skillLevels = SKILL_LEVELS;
  categories = signal<any[]>([]);
  certificateTemplates = signal<any[]>([]);
  badges = signal<any[]>([]);

  // Form options (labels resolved through i18n so they follow the active language)
  get pathTypes(): { value: PathType; label: string; description: string }[] {
    return [
      { value: 'sequential', label: this.i18n.t('paths_admin.form.pt_sequential'), description: this.i18n.t('paths_admin.form.pt_sequential_desc') },
      { value: 'flexible', label: this.i18n.t('paths_admin.form.pt_flexible'), description: this.i18n.t('paths_admin.form.pt_flexible_desc') },
      { value: 'milestone', label: this.i18n.t('paths_admin.form.pt_milestone'), description: this.i18n.t('paths_admin.form.pt_milestone_desc') }
    ];
  }

  get completionTypes(): { value: CompletionType; label: string; description: string }[] {
    return [
      { value: 'all_items', label: this.i18n.t('paths_admin.form.ct_all_items'), description: this.i18n.t('paths_admin.form.ct_all_items_desc') },
      { value: 'percentage', label: this.i18n.t('paths_admin.form.ct_percentage'), description: this.i18n.t('paths_admin.form.ct_percentage_desc') },
      { value: 'points', label: this.i18n.t('paths_admin.form.ct_points'), description: this.i18n.t('paths_admin.form.ct_points_desc') },
      { value: 'assessment', label: this.i18n.t('paths_admin.form.ct_assessment'), description: this.i18n.t('paths_admin.form.ct_assessment_desc') }
    ];
  }

  get visibilityOptions(): { value: Visibility; label: string }[] {
    return [
      { value: 'public', label: this.i18n.t('paths_admin.form.vis_public') },
      { value: 'private', label: this.i18n.t('paths_admin.form.vis_private') },
      { value: 'restricted', label: this.i18n.t('paths_admin.form.vis_restricted') },
      { value: 'enrolled_only', label: this.i18n.t('paths_admin.form.vis_enrolled_only') }
    ];
  }

  get enrollmentTypes(): { value: EnrollmentType; label: string }[] {
    return [
      { value: 'open', label: this.i18n.t('paths_admin.form.et_open') },
      { value: 'approval_required', label: this.i18n.t('paths_admin.form.et_approval') },
      { value: 'invitation_only', label: this.i18n.t('paths_admin.form.et_invitation') },
      { value: 'auto_enroll', label: this.i18n.t('paths_admin.form.et_auto') }
    ];
  }

  // Tags input
  newTag = '';

  // Learning objectives input
  newObjective = '';

  constructor(
    private fb: FormBuilder,
    private route: ActivatedRoute,
    private router: Router,
    private learningPathService: LearningPathService,
    private i18n: I18nService
  ) {}

  ngOnInit(): void {
    this.initForm();
    this.loadReferenceData();

    this.route.data.pipe(takeUntil(this.destroy$)).subscribe(data => {
      this.mode = data['mode'] || 'create';
    });

    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      if (params['id']) {
        this.pathId = String(params['id']);
        this.loadPath(this.pathId);
      } else {
        this.loading.set(false);
      }
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  initForm(): void {
    this.form = this.fb.group({
      // Basic Info
      title: ['', [Validators.required, Validators.maxLength(255)]],
      short_description: ['', [Validators.required, Validators.maxLength(500)]],
      description: [''],
      thumbnail_url: [''],
      banner_url: [''],
      category_id: [null],
      skill_level: ['beginner'],

      // Structure
      path_type: ['sequential'],
      is_mandatory: [false],
      enforce_prerequisites: [true],
      allow_skip: [false],

      // Duration
      estimated_duration_hours: [null],
      estimated_weeks: [null],
      weekly_commitment_hours: [null],

      // Completion
      completion_type: ['all_items'],
      completion_threshold: [100],
      passing_score: [70],

      // Certificate
      certificate_enabled: [false],
      certificate_template_id: [null],
      certificate_validity_months: [null],

      // Gamification
      total_points: [0],
      badge_id: [null],

      // Visibility & Access
      visibility: ['public'],
      enrollment_type: ['open'],
      max_enrollments: [null],
      enrollment_start_date: [null],
      enrollment_end_date: [null],

      // Pricing
      is_free: [true],
      price: [0],
      currency: ['USD'],

      // Meta
      tags: [[]],
      learning_objectives: [[]],
      prerequisites_description: ['']
    });
  }

  loadPath(pathId: string): void {
    this.learningPathService.getById(pathId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (path) => {
        this.path.set(path);
        this.patchForm(path);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.router.navigate(['/admin/learning-paths']);
      }
    });
  }

  patchForm(path: LearningPath): void {
    this.form.patchValue({
      title: path.title,
      short_description: path.short_description,
      description: path.description,
      thumbnail_url: path.thumbnail_url,
      banner_url: path.banner_url,
      category_id: path.category_id,
      skill_level: path.skill_level,
      path_type: path.path_type,
      is_mandatory: path.is_mandatory,
      enforce_prerequisites: path.enforce_prerequisites,
      allow_skip: path.allow_skip,
      estimated_duration_hours: path.estimated_duration_hours,
      estimated_weeks: path.estimated_weeks,
      weekly_commitment_hours: path.weekly_commitment_hours,
      completion_type: path.completion_type,
      completion_threshold: path.completion_threshold,
      passing_score: path.passing_score,
      certificate_enabled: path.certificate_enabled,
      certificate_template_id: path.certificate_template_id,
      certificate_validity_months: path.certificate_validity_months,
      total_points: path.total_points,
      badge_id: path.badge_id,
      visibility: path.visibility,
      enrollment_type: path.enrollment_type,
      max_enrollments: path.max_enrollments,
      enrollment_start_date: path.enrollment_start_date?.split('T')[0],
      enrollment_end_date: path.enrollment_end_date?.split('T')[0],
      is_free: path.is_free,
      price: path.price,
      currency: path.currency,
      tags: path.tags || [],
      learning_objectives: path.learning_objectives || [],
      prerequisites_description: path.prerequisites_description
    });
  }

  loadReferenceData(): void {
    // Load categories, certificates, badges from respective services
    // For now using placeholder data
    this.categories.set([
      { category_id: 1, name: 'Compliance' },
      { category_id: 2, name: 'Leadership' },
      { category_id: 3, name: 'Technical Skills' },
      { category_id: 4, name: 'Soft Skills' }
    ]);

    this.certificateTemplates.set([
      { template_id: 1, name: 'Standard Completion' },
      { template_id: 2, name: 'Professional Certificate' }
    ]);

    this.badges.set([
      { badge_id: 1, name: 'Path Completer', icon: '🏆' },
      { badge_id: 2, name: 'Fast Learner', icon: '⚡' }
    ]);
  }

  setActiveTab(tab: string): void {
    this.activeTab.set(tab);
  }

  // Tags management
  addTag(): void {
    if (this.newTag.trim()) {
      const tags = [...this.form.value.tags];
      if (!tags.includes(this.newTag.trim())) {
        tags.push(this.newTag.trim());
        this.form.patchValue({ tags });
      }
      this.newTag = '';
    }
  }

  removeTag(index: number): void {
    const tags = [...this.form.value.tags];
    tags.splice(index, 1);
    this.form.patchValue({ tags });
  }

  // Learning objectives management
  addObjective(): void {
    if (this.newObjective.trim()) {
      const objectives = [...this.form.value.learning_objectives];
      objectives.push(this.newObjective.trim());
      this.form.patchValue({ learning_objectives: objectives });
      this.newObjective = '';
    }
  }

  removeObjective(index: number): void {
    const objectives = [...this.form.value.learning_objectives];
    objectives.splice(index, 1);
    this.form.patchValue({ learning_objectives: objectives });
  }

  onSubmit(): void {
    if (this.form.invalid) {
      this.markFormTouched();
      return;
    }

    this.saving.set(true);
    const data: LearningPathCreateRequest = this.form.value;

    if (this.mode === 'edit' && this.pathId) {
      this.learningPathService.update(this.pathId, data).pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.saving.set(false);
          this.router.navigate(['/admin/learning-paths', this.pathId]);
        },
        error: () => this.saving.set(false)
      });
    } else {
      this.learningPathService.create(data).pipe(takeUntil(this.destroy$)).subscribe({
        next: (response) => {
          this.saving.set(false);
          if (response?.path_id) {
            this.router.navigate(['/admin/learning-paths', response?.path_id, 'builder']);
          }
        },
        error: () => this.saving.set(false)
      });
    }
  }

  markFormTouched(): void {
    Object.keys(this.form.controls).forEach(key => {
      this.form.get(key)?.markAsTouched();
    });
  }

  isFieldInvalid(field: string): boolean {
    const control = this.form.get(field);
    return control ? control.invalid && control.touched : false;
  }

  cancel(): void {
    if (this.pathId) {
      this.router.navigate(['/admin/learning-paths', this.pathId]);
    } else {
      this.router.navigate(['/admin/learning-paths']);
    }
  }
}
