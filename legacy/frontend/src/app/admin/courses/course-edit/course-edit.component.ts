// courses/components/course-edit/course-edit.component.ts
import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CourseService } from '../course.service';
import { Course, CourseForm, CourseDropdowns } from '../course.models';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TuiIcon} from '@taiga-ui/core';

@Component({
  selector: 'app-course-edit',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TuiIcon],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
        <div class="course-edit">
      <!-- Header -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
          <a routerLink="/courses">
            <div class="avatar text-center avatar-md rounded text-primary-emphasis">
              <i class="fs-5">
                <tui-icon icon="@tui.chevron-left"></tui-icon>
              </i>
            </div>
          </a>
          <div>
            <h4 class="fw-bold mb-0">{{ isEdit ? 'Edit Course' : 'Create Course' }}</h4>
            <p class="text-muted small mb-0" *ngIf="course">{{ course.title }}</p>
          </div>
        </div>
        <div class="d-flex gap-2" *ngIf="!loading">
          <span class="badge fs-6 align-self-center" [class]="getStatusBadge(form.status || 'draft')">{{ (form.status || 'draft') | titlecase }}</span>
        </div>
      </div>

      <div *ngIf="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>

      <div *ngIf="!loading" class="row g-4">
        <!-- ═══ Left: Main form ═══ -->
        <div class="col-lg-8">
          <!-- Basic Info -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-2"></i>Basic Information</div>
            <div class="card-body">
              <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label small fw-semibold mb-0">Course Title <span class="text-danger">*</span></label>
                  <button type="button" class="btn btn-outline-primary btn-sm py-0" (click)="rewriteFieldValue('title')">AI Rewrite</button>
                </div>
                <input type="text" class="form-control" [(ngModel)]="form.title" name="title" placeholder="e.g. Introduction to Cybersecurity">
              </div>
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Course Type</label>
                  <select class="form-select form-select-sm" [(ngModel)]="form.course_type" name="course_type">
                    <option value="Regular">Regular</option>
                    <option value="SCORM">SCORM</option>
                    <option value="ILT">Instructor-Led Training</option>
                    <option value="Blended">Blended</option>
                    <option value="Webinar">Webinar</option>
                    <option value="External">External</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small fw-semibold mb-0">Version</label>
                    <button type="button" class="btn btn-outline-primary btn-sm py-0" (click)="rewriteFieldValue('version')">AI Rewrite</button>
                  </div>
                  <input type="text" class="form-control form-control-sm" [(ngModel)]="form.version" name="version" placeholder="1.0">
                </div>
              </div>
              <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label small fw-semibold mb-0">Summary <span class="text-muted fw-normal">(max 500 chars)</span></label>
                  <button type="button" class="btn btn-outline-primary btn-sm py-0" (click)="rewriteFieldValue('summary')">AI Rewrite</button>
                </div>
                <textarea class="form-control form-control-sm" rows="2" [(ngModel)]="form.summary" name="summary"
                          maxlength="500" placeholder="Brief summary shown in course cards..."></textarea>
                <small class="text-muted float-end">{{ (form.summary?.length || 0) }}/500</small>
              </div>
              <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label small fw-semibold mb-0">Description</label>
                  <button type="button" class="btn btn-outline-primary btn-sm py-0" (click)="rewriteFieldValue('description')">AI Rewrite</button>
                </div>
                <textarea class="form-control form-control-sm" rows="4" [(ngModel)]="form.description" name="description"
                          placeholder="Full course description..."></textarea>
              </div>
              <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label small fw-semibold mb-0">Learning Objectives</label>
                  <button type="button" class="btn btn-outline-primary btn-sm py-0" (click)="rewriteFieldValue('objectives')">AI Rewrite</button>
                </div>
                <textarea class="form-control form-control-sm" rows="3" [(ngModel)]="form.objectives" name="objectives"
                          placeholder="What learners will achieve by completing this course..."></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Thumbnail URL</label>
                <div class="input-group input-group-sm">
                  <span class="input-group-text"><i class="bi bi-image"></i></span>
                  <input type="url" class="form-control" [(ngModel)]="form.thumbnail_url" name="thumbnail_url" placeholder="https://...">
                </div>
                <div class="mt-2" *ngIf="form.thumbnail_url">
                  <img [src]="form.thumbnail_url" class="rounded" style="max-height:120px; max-width:200px" alt="Thumbnail"
                       (error)="form.thumbnail_url = ''">
                </div>
              </div>
            </div>
          </div>

          <!-- Assessment & Completion -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-award me-2"></i>Assessment & Completion</div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Pass Mark (%)</label>
                  <input type="number" class="form-control form-control-sm" [(ngModel)]="form.pass_mark" name="pass_mark" min="0" max="100">
                </div>
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Pass Percentage (%)</label>
                  <input type="number" class="form-control form-control-sm" [(ngModel)]="form.pass_percentage" name="pass_percentage" min="0" max="100">
                </div>
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Duration (minutes)</label>
                  <input type="number" class="form-control form-control-sm" [(ngModel)]="form.duration_minutes" name="duration_minutes" min="0" step="0.5">
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label small fw-semibold">Certificate Template</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.certificate_template_id" name="certificate_template_id">
                  <option [ngValue]="null">No certificate</option>
                  <option *ngFor="let t of dropdowns.certificate_templates" [ngValue]="t.template_id">{{ t.template_name }}</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Schedule & Enrollment -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar me-2"></i>Schedule & Enrollment</div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Start Date</label>
                  <input type="date" class="form-control form-control-sm" [(ngModel)]="form.start_date" name="start_date">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">End Date</label>
                  <input type="date" class="form-control form-control-sm" [(ngModel)]="form.end_date" name="end_date">
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Enrollment Limit</label>
                  <input type="number" class="form-control form-control-sm" [(ngModel)]="form.enrollment_limit" name="enrollment_limit" min="0" placeholder="0 = unlimited">
                  <small class="text-muted">0 means no limit</small>
                </div>
                <div class="col-md-6 d-flex align-items-end pb-3">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="selfEnroll"
                           [checked]="form.allow_self_enrollment" (change)="form.allow_self_enrollment = $any($event.target).checked ? 1 : 0">
                    <label class="form-check-label small" for="selfEnroll">Allow self-enrollment</label>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ═══ Right: Sidebar ═══ -->
        <div class="col-lg-4">
          <!-- Publish -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-send me-2"></i>Publish</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label small fw-semibold">Status</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.status" name="status">
                  <option value="draft">Draft</option>
                  <option value="published">Published</option>
                  <option value="archived">Archived</option>
                </select>
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-primary btn-sm flex-grow-1" (click)="save()" [disabled]="saving || !form.title || !form.instructor_id">
                  <span *ngIf="saving" class="spinner-border spinner-border-sm me-1"></span>
                  <i *ngIf="!saving" class="bi bi-check-lg me-1"></i>{{ isEdit ? 'Update' : 'Create' }}
                </button>
                <button class="btn btn-success btn-sm" *ngIf="isEdit && form.status === 'draft'" (click)="form.status='published'; save()" [disabled]="saving">
                  <i class="bi bi-send me-1"></i>Publish
                </button>
              </div>
              <div class="alert alert-success py-1 small mt-2" *ngIf="saveSuccess">{{ saveSuccess }}</div>
              <div class="alert alert-danger py-1 small mt-2" *ngIf="saveError">{{ saveError }}</div>
            </div>
          </div>

          <!-- Classification -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-tag me-2"></i>Classification</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label small fw-semibold">Category</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.category_id" name="category_id">
                  <option [ngValue]="null">Uncategorized</option>
                  <option *ngFor="let c of dropdowns.categories" [ngValue]="c.category_id">{{ c.name }}</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Level</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.level" name="level">
                  <option value="">Not specified</option>
                  <option value="Beginner">Beginner</option>
                  <option value="Intermediate">Intermediate</option>
                  <option value="Advanced">Advanced</option>
                  <option value="Expert">Expert</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Instructor <span class="text-danger">*</span></label>
                <select class="form-select form-select-sm" [(ngModel)]="form.instructor_id" name="instructor_id">
                  <option [ngValue]="0" disabled>Select instructor...</option>
                  <option *ngFor="let i of dropdowns.instructors" [ngValue]="i.id">{{ i.name }}</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Flags -->
          <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-toggles me-2"></i>Settings</div>
            <div class="card-body">
              <div class="form-check form-switch mb-2" *ngFor="let flag of flags">
                <input class="form-check-input" type="checkbox" [id]="flag.key"
                       [checked]="$any(form)[flag.key]" (change)="$any(form)[flag.key] = $any($event.target).checked ? 1 : 0">
                <label class="form-check-label small" [for]="flag.key">{{ flag.label }}</label>
              </div>
            </div>
          </div>

          <!-- Info (edit mode only) -->
          <div class="card border-0 shadow-sm" *ngIf="isEdit && course">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart me-2"></i>Course Info</div>
            <div class="card-body">
              <div class="row mb-2" *ngFor="let info of courseInfoItems">
                <div class="col-6 text-muted small">{{ info.label }}</div>
                <div class="col-6 small fw-medium" [innerHTML]="info.value"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
      </div>
    </main>
  `
})
export class CourseEditComponent implements OnInit {
  isEdit = false;
  loading = false;
  saving = false;
  saveSuccess = '';
  saveError = '';
  courseId = 0;
  course: Course | null = null;

  form: CourseForm = {
    title: '', course_type: 'Regular', description: '', objectives: '', summary: '',
    thumbnail_url: '', category_id: null, pass_mark: 70, instructor_id: 0,
    certificate_template_id: null, is_mandatory: 0, status: 'draft', level: '',
    pass_percentage: 70, duration_minutes: null, is_featured: 0, is_active: 1,
    is_onboarding: 0, enrollment_limit: 0, allow_self_enrollment: 1,
    start_date: '', end_date: '', version: '1.0'
  };

  dropdowns: CourseDropdowns = { categories: [], instructors: [], certificate_templates: [] };

  flags = [
    { key: 'is_active', label: 'Active' },
    { key: 'is_mandatory', label: 'Mandatory' },
    { key: 'is_featured', label: 'Featured' },
    { key: 'is_onboarding', label: 'Onboarding Course' },
  ];

  courseInfoItems: { label: string; value: string }[] = [];

  constructor(private svc: CourseService, private route: ActivatedRoute, private router: Router) {}

  ngOnInit(): void {
    this.courseId = +(this.route.snapshot.paramMap.get('id') || 0);
    this.isEdit = !!this.courseId;

    this.svc.getDropdowns().subscribe(d => this.dropdowns = d);

    if (this.isEdit) {
      this.loading = true;
      this.svc.getCourse(this.courseId).subscribe({
        next: c => {
          this.course = c;
          this.form = {
            title: c.title, course_type: c.course_type || 'Regular',
            description: c.description || '', objectives: c.objectives || '',
            summary: c.summary || '', thumbnail_url: c.thumbnail_url || '',
            category_id: c.category_id, pass_mark: c.pass_mark || 70,
            instructor_id: c.instructor_id, certificate_template_id: c.certificate_template_id,
            is_mandatory: c.is_mandatory, status: c.status || 'draft',
            level: c.level || '', pass_percentage: c.pass_percentage || 70,
            duration_minutes: c.duration_minutes, is_featured: c.is_featured,
            is_active: c.is_active, is_onboarding: c.is_onboarding,
            enrollment_limit: c.enrollment_limit || 0, allow_self_enrollment: c.allow_self_enrollment,
            start_date: c.start_date || '', end_date: c.end_date || '',
            version: c.version || '1.0'
          };
          this.buildInfoItems(c);
          this.loading = false;
        },
        error: () => { this.saveError = 'Failed to load course'; this.loading = false; }
      });
    }
  }

  private buildInfoItems(c: Course): void {
    const rating = c.average_rating
      ? `${Number(c.average_rating).toFixed(1)} <i class="bi bi-star-fill text-warning"></i> (${c.rating_count})`
      : '<span class="text-muted">No ratings</span>';
    this.courseInfoItems = [
      { label: 'Enrollments', value: String(c.enrollment_count || 0) },
      { label: 'Avg Rating', value: rating },
      { label: 'Created By', value: c.created_by_name || '-' },
      { label: 'Created', value: c.created_at ? new Date(c.created_at).toLocaleDateString() : '-' },
      { label: 'Updated', value: c.updated_at ? new Date(c.updated_at).toLocaleDateString() : '-' },
    ];
  }

  rewriteFieldValue(fieldName: 'title' | 'version' | 'summary' | 'description' | 'objectives'): void {
    const formValues = this.form as unknown as Record<string, string | number | null | undefined>;
    const currentValue = String(formValues[fieldName] ?? '').trim();
    if (!currentValue) return;

    let rewritten = currentValue.replace(/\s+/g, ' ').trim();

    switch (fieldName) {
      case 'title':
        rewritten = `Master ${rewritten.replace(/^Master\s+/i, '')} with practical guidance`;
        break;
      case 'version':
        rewritten = `v${rewritten.replace(/^v/i, '')}`;
        break;
      case 'summary':
        rewritten = `A concise, practical course that helps learners ${rewritten.toLowerCase()} and build confidence.`;
        break;
      case 'description':
      case 'objectives':
        rewritten = `This course is designed to help learners ${rewritten.toLowerCase()} through a structured and engaging experience.`;
        break;
    }

    formValues[fieldName] = rewritten;
  }

  save(): void {
    this.saving = true;
    this.saveSuccess = '';
    this.saveError = '';

    const obs = this.isEdit
      ? this.svc.updateCourse(this.courseId, this.form)
      : this.svc.createCourse(this.form);

    obs.subscribe({
      next: (res: any) => {
        this.saving = false;
        const r = res.data || res;
        if (r.success === false) { this.saveError = r.error || 'Failed'; return; }

        if (!this.isEdit && r.course_id) {
          this.saveSuccess = 'Course created!';
          setTimeout(() => this.router.navigate(['/courses', r.course_id, 'edit']), 1000);
        } else {
          this.saveSuccess = 'Course updated';
          setTimeout(() => this.saveSuccess = '', 3000);
        }
      },
      error: (e: any) => { this.saving = false; this.saveError = e?.error?.message || 'Save failed'; }
    });
  }

  duplicate(): void {
    if (!confirm('Create a copy of this course?')) return;
    this.svc.duplicateCourse(this.courseId).subscribe({
      next: (res: any) => {
        const r = res.data || res;
        if (r.course_id) this.router.navigate(['/courses', r.course_id, 'edit']);
      },
      error: () => alert('Failed to duplicate')
    });
  }

  archive(): void {
    if (!confirm('Archive this course? It will be hidden from learners.')) return;
    this.svc.deleteCourse(this.courseId).subscribe({
      next: () => this.router.navigate(['/courses']),
      error: () => alert('Failed to archive')
    });
  }

  getStatusBadge(status: string): string {
    return { published: 'bg-success', draft: 'bg-warning text-dark', archived: 'bg-secondary' }[status] || 'bg-secondary';
  }

  goBack() {
    this.router.navigate(['/courses']).then(r => console.log(r));
  }
}
