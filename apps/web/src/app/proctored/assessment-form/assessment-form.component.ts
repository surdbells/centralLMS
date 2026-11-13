// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 10
// Admin: Assessment Form Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, forkJoin, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { ApiClient, Endpoints } from '../../core/api';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { SelectComponent } from '../../shared/select/select.component';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import {
  AssessmentAdminService,
  type AssessmentDetail,
  type AssessmentInput,
  type AssessmentSection as ApiSection,
  type AttachedQuestion,
  type AssignmentType,
  type QuestionBank,
} from '../assessment-admin.service';

// ==========================================
// INTERFACES
// ==========================================

interface AssessmentForm {
  assessment_id: string | null;
  title: string;
  description: string;
  instructions: string;
  assessment_type: 'quiz' | 'exam' | 'practice' | 'survey';
  category_id: string | null;
  status: 'draft' | 'published' | 'closed';
  // Time & Attempts
  time_limit_minutes: number;
  max_attempts: number;
  attempt_cooldown_hours: number;
  // Scoring
  passing_score: number;
  passing_type: 'percentage' | 'points';
  negative_marking: boolean;
  negative_marking_percentage: number;
  partial_credit: boolean;
  // Display
  questions_per_page: number;
  navigation_type: 'free' | 'linear' | 'section';
  shuffle_questions: boolean;
  shuffle_options: boolean;
  show_question_numbers: boolean;
  show_progress_bar: boolean;
  // Results
  show_results: boolean;
  show_correct_answers: boolean;
  show_explanations: boolean;
  show_score: boolean;
  results_release_type: 'immediate' | 'after_close' | 'manual' | 'date';
  results_release_date: string;
  // Proctoring
  proctoring_level: 'none' | 'basic' | 'standard' | 'advanced';
  // Availability
  availability_start: string;
  availability_end: string;
  // Integration
  course_id: string | null;
  training_id: string | null;
  // Grading
  grading_scale_id: string | null;
  // Messaging
  welcome_message: string;
  completion_message: string;
}

interface Section {
  section_id: string;
  title: string;
  description: string;
  sort_order: number;
  questions: SectionQuestion[];
  is_collapsed: boolean;
  is_editing: boolean;
}

interface SectionQuestion {
  // The question's id doubles as the assessment-question handle: the REST API
  // keys attach/detach/points/section on the question id, so there is no
  // separate `aq_id` any more.
  aq_id: string;
  question_id: string;
  question_text: string;
  question_type: string;
  points: number;
  sort_order: number;
  is_required: boolean;
}

interface Assignment {
  assignment_id: string;
  assignment_type: 'user' | 'department' | 'team' | 'branch' | 'all';
  target_id: string | null;
  target_name: string;
  due_date: string;
  created_at: string;
}

interface Category { category_id: string; name: string; }
interface Course { id: string; title: string; }
interface Training { id: string; title: string; }
interface GradingScale { scale_id: string; name: string; }

@Component({
  selector: 'app-assessment-form',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent, SelectComponent],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page assessment-form-container">
      <!-- Header -->
      <header class="ui-page-header">
        <button type="button" class="ui-page-header__back" (click)="goBack()" aria-label="Back">
          <i class="bi bi-chevron-left"></i>
        </button>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-pencil-square"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ isEdit ? 'Edit Assessment' : 'New Assessment' }}</h1>
          <div class="form-breadcrumb" *ngIf="isEdit">
            <span class="ui-pill type-badge" [ngClass]="'type-' + form.assessment_type">{{ form.assessment_type }}</span>
            <span class="ui-pill status-badge" [ngClass]="'status-' + form.status"><span class="ui-pill__dot"></span>{{ form.status }}</span>
            <span class="ui-muted small" *ngIf="form.assessment_id">#{{ form.assessment_id }}</span>
          </div>
          <p *ngIf="!isEdit">Set up the details, questions and rules for a new assessment</p>
        </div>
        <div class="ui-page-header__actions">
          <button type="button" class="btn btn-outline-secondary" (click)="saveDraft()" [disabled]="saving">
            <i class="bi bi-floppy"></i> Save Draft
          </button>
          <button type="button" class="btn btn-primary" (click)="saveAndPublish()" [disabled]="saving" *ngIf="form.status !== 'published'">
            <i class="bi bi-send"></i> Publish
          </button>
          <button type="button" class="btn btn-primary" (click)="save()" [disabled]="saving" *ngIf="form.status === 'published'">
            <span class="spinner-border spinner-border-sm me-1" *ngIf="saving"></span> Save Changes
          </button>
        </div>
      </header>

      <!-- Tab Navigation -->
      <nav class="ui-tabs" role="tablist">
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab === 'details'" (click)="activeTab = 'details'">
          <i class="bi bi-file-earmark-text"></i> Details
        </button>
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab === 'questions'" (click)="activeTab = 'questions'">
          <i class="bi bi-list-ol"></i> Sections & Questions
          <span class="ui-tab__count" *ngIf="totalQuestionCount > 0">{{ totalQuestionCount }}</span>
        </button>
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab === 'settings'" (click)="activeTab = 'settings'">
          <i class="bi bi-gear"></i> Settings
        </button>
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab === 'assignments'" (click)="activeTab = 'assignments'"
                *ngIf="isEdit">
          <i class="bi bi-people"></i> Assignments
          <span class="ui-tab__count" *ngIf="assignments.length > 0">{{ assignments.length }}</span>
        </button>
      </nav>

      <!-- ==========================================
           TAB 1: DETAILS
           ========================================== -->
      <div class="tab-content" *ngIf="activeTab === 'details'">
        <!-- Assessment Type Selector -->
        <section class="ui-section type-selector" *ngIf="!isEdit">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Assessment Type</h2><p class="ui-section__subtitle">Choose what kind of assessment this is</p></div>
          </div>
          <div class="type-cards">
            <div class="type-card" *ngFor="let t of assessmentTypes" [class.selected]="form.assessment_type === t.value"
                 (click)="form.assessment_type = t.value">
              <tui-icon [icon]="t.icon"></tui-icon>
              <h4>{{ t.label }}</h4>
              <p>{{ t.description }}</p>
            </div>
          </div>
        </section>

        <div class="form-grid">
          <!-- Left Column -->
          <section class="ui-section form-col">
            <div class="ui-section__head">
              <div><h2 class="ui-section__title">Details</h2><p class="ui-section__subtitle">What learners see before and after they sit it</p></div>
            </div>
            <div class="form-group">
              <label>Title <span class="required">*</span></label>
              <input type="text" class="form-control" [(ngModel)]="form.title" placeholder="Enter assessment title" maxlength="255">
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea class="form-control" [(ngModel)]="form.description" rows="3"
                        placeholder="Brief description visible to learners"></textarea>
            </div>
            <div class="form-group">
              <label>Instructions</label>
              <textarea class="form-control" [(ngModel)]="form.instructions" rows="4"
                        placeholder="Instructions shown before starting the assessment"></textarea>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Welcome Message</label>
                <input type="text" class="form-control" [(ngModel)]="form.welcome_message" placeholder="Shown on start screen">
              </div>
              <div class="form-group">
                <label>Completion Message</label>
                <input type="text" class="form-control" [(ngModel)]="form.completion_message" placeholder="Shown after submission">
              </div>
            </div>
          </section>

          <!-- Right Column -->
          <section class="ui-section form-col sidebar-col">
            <div class="ui-section__head">
              <div><h2 class="ui-section__title">Organisation</h2><p class="ui-section__subtitle">Category and links</p></div>
            </div>
            <div class="form-group">
              <label>Category</label>
              <app-select [options]="categories" bindLabel="name" bindValue="category_id" placeholder="No Category" [clearable]="true" [(ngModel)]="form.category_id" ariaLabel="Category" />
            </div>
            <div class="form-group">
              <label>Linked Course</label>
              <app-select [options]="courses" bindLabel="title" bindValue="id" placeholder="None" [clearable]="true" [(ngModel)]="form.course_id" ariaLabel="Linked Course" />
            </div>
            <div class="form-group">
              <label>Linked Training</label>
              <app-select [options]="trainings" bindLabel="title" bindValue="id" placeholder="None" [clearable]="true" [(ngModel)]="form.training_id" ariaLabel="Linked Training" />
            </div>
            <div class="form-group" *ngIf="isEdit">
              <label>Status</label>
              <app-select [options]="statusOpts" [searchable]="false" [(ngModel)]="form.status" ariaLabel="Status" />
            </div>
          </section>
        </div>
      </div>

      <!-- ==========================================
           TAB 2: SECTIONS & QUESTIONS
           ========================================== -->
      <section class="ui-section tab-content" *ngIf="activeTab === 'questions'">
        <div class="ui-section__head section-toolbar">
          <div>
            <h2 class="ui-section__title">Sections & Questions</h2>
            <p class="ui-section__subtitle section-info">
              <span>{{ sections.length }} Section{{ sections.length !== 1 ? 's' : '' }}</span>
              <span class="sep">·</span>
              <span>{{ totalQuestionCount }} Questions</span>
              <span class="sep">·</span>
              <span>{{ totalPoints }} Points</span>
            </p>
          </div>
          <div class="ui-section__actions section-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="addSection()">
              <i class="bi bi-plus-lg"></i> Add Section
            </button>
            <button type="button" class="btn btn-sm btn-primary" (click)="showQuestionPicker = true" *ngIf="sections.length > 0">
              <i class="bi bi-plus-circle"></i> Add Questions
            </button>
          </div>
        </div>

        <!-- Sections -->
        <div class="section-list" *ngIf="sections.length > 0">
          <div class="section-block" *ngFor="let s of sections; let si = index">
            <div class="section-header" (click)="s.is_collapsed = !s.is_collapsed">
              <div class="section-drag">
                <tui-icon icon="@tui.grip-vertical"></tui-icon>
              </div>
              <div class="section-title-area" *ngIf="!s.is_editing">
                <h4>{{ s.title || 'Untitled Section' }}</h4>
                <span class="section-count">{{ s.questions.length }} questions · {{ getSectionPoints(s) }} pts</span>
              </div>
              <div class="section-edit-area" *ngIf="s.is_editing" (click)="$event.stopPropagation()">
                <input type="text" class="form-control form-control-sm" [(ngModel)]="s.title" placeholder="Section title">
                <input type="text" class="form-control form-control-sm mt-1" [(ngModel)]="s.description" placeholder="Description (optional)">
                <div class="mt-1">
                  <button class="btn btn-sm btn-primary" (click)="saveSection(s)">Save</button>
                  <button class="btn btn-sm btn-link" (click)="s.is_editing = false">Cancel</button>
                </div>
              </div>
              <div class="section-header-actions" (click)="$event.stopPropagation()">
                <button class="btn btn-sm btn-ghost" (click)="s.is_editing = true" title="Edit">
                  <tui-icon icon="@tui.edit-3"></tui-icon>
                </button>
                <button class="btn btn-sm btn-ghost" (click)="moveSection(si, -1)" [disabled]="si === 0" title="Move Up">
                  <tui-icon icon="@tui.chevron-up"></tui-icon>
                </button>
                <button class="btn btn-sm btn-ghost" (click)="moveSection(si, 1)" [disabled]="si === sections.length - 1" title="Move Down">
                  <tui-icon icon="@tui.chevron-down"></tui-icon>
                </button>
                <button class="btn btn-sm btn-ghost text-danger" (click)="deleteSection(s)" title="Delete">
                  <tui-icon icon="@tui.trash-2"></tui-icon>
                </button>
                <tui-icon [icon]="s.is_collapsed ? '@tui.chevron-right' : '@tui.chevron-down'" class="collapse-icon"></tui-icon>
              </div>
            </div>

            <!-- Questions in section -->
            <div class="section-body" *ngIf="!s.is_collapsed">
              <div class="question-row" *ngFor="let q of s.questions; let qi = index">
                <span class="q-num">{{ getGlobalQNum(si, qi) }}</span>
                <span class="q-type-icon" [title]="q.question_type">
                  <tui-icon [icon]="getTypeIcon(q.question_type)"></tui-icon>
                </span>
                <span class="q-text">{{ q.question_text | slice:0:100 }}{{ q.question_text.length > 100 ? '...' : '' }}</span>
                <span class="q-points">
                  <input type="number" class="points-input" [(ngModel)]="q.points" min="0" max="100"
                         (change)="updateQuestionPoints(q)" (click)="$event.stopPropagation()">
                  pts
                </span>
                <div class="q-actions">
                  <button class="btn btn-sm btn-ghost" (click)="moveQuestion(s, qi, -1)" [disabled]="qi === 0">
                    <tui-icon icon="@tui.chevron-up"></tui-icon>
                  </button>
                  <button class="btn btn-sm btn-ghost" (click)="moveQuestion(s, qi, 1)" [disabled]="qi === s.questions.length - 1">
                    <tui-icon icon="@tui.chevron-down"></tui-icon>
                  </button>
                  <app-select #mv class="move-select" size="sm" style="width:140px" *ngIf="sections.length > 1"
                              [options]="moveOpts(si)" placeholder="Move..." [value]="null"
                              (selectionChange)="moveToSection(s, qi, $event); mv.writeValue(null)" ariaLabel="Move to section" />
                  <button class="btn btn-sm btn-ghost text-danger" (click)="removeQuestion(s, qi)">
                    <tui-icon icon="@tui.x"></tui-icon>
                  </button>
                </div>
              </div>
              <div class="section-empty" *ngIf="s.questions.length === 0">
                <p>No questions in this section</p>
                <button class="btn btn-sm btn-primary" (click)="showQuestionPicker = true; pickerTargetSection = s">
                  <tui-icon icon="@tui.plus"></tui-icon> Add Questions
                </button>
              </div>
            </div>
          </div>
        </div>

        <div class="ui-state" *ngIf="sections.length === 0">
          <span class="ui-state__icon"><i class="bi bi-layers"></i></span>
          <h3>No sections yet</h3>
          <p>Create a section to start adding questions</p>
          <button type="button" class="btn btn-primary" (click)="addSection()">
            <i class="bi bi-plus-lg"></i> Add First Section
          </button>
        </div>
      </section>

      <!-- ==========================================
           TAB 3: SETTINGS
           ========================================== -->
      <div class="tab-content" *ngIf="activeTab === 'settings'">
        <div class="settings-grid">
          <!-- Time & Attempts -->
          <div class="ui-section settings-section">
            <h3 class="settings-title"><tui-icon icon="@tui.clock"></tui-icon> Time & Attempts</h3>
            <div class="form-group">
              <label>Time Limit (minutes)</label>
              <input type="number" class="form-control" [(ngModel)]="form.time_limit_minutes" min="0" max="600">
              <small class="text-muted">0 = no time limit</small>
            </div>
            <div class="form-group">
              <label>Maximum Attempts</label>
              <input type="number" class="form-control" [(ngModel)]="form.max_attempts" min="0" max="100">
              <small class="text-muted">0 = unlimited</small>
            </div>
            <div class="form-group">
              <label>Cooldown Between Attempts (hours)</label>
              <input type="number" class="form-control" [(ngModel)]="form.attempt_cooldown_hours" min="0" max="720">
            </div>
          </div>

          <!-- Scoring -->
          <div class="ui-section settings-section">
            <h3 class="settings-title"><tui-icon icon="@tui.award"></tui-icon> Scoring</h3>
            <div class="form-group">
              <label>Passing Score</label>
              <div class="input-group">
                <input type="number" class="form-control" [(ngModel)]="form.passing_score" min="0">
                <app-select style="max-width: 130px;" [options]="passingTypeOpts" [searchable]="false" [(ngModel)]="form.passing_type" ariaLabel="Passing type" />
              </div>
            </div>
            <div class="form-group">
              <label>Grading Scale</label>
              <app-select [options]="gradingScales" bindLabel="name" bindValue="scale_id" placeholder="Default (A-F)" [clearable]="true" [(ngModel)]="form.grading_scale_id" ariaLabel="Grading Scale" />
            </div>
            <div class="toggle-row">
              <label>Negative Marking</label>
              <input type="checkbox" class="form-check-input" [(ngModel)]="form.negative_marking">
            </div>
            <div class="form-group" *ngIf="form.negative_marking">
              <label>Penalty Percentage</label>
              <input type="number" class="form-control" [(ngModel)]="form.negative_marking_percentage" min="0" max="100">
            </div>
            <div class="toggle-row">
              <label>Partial Credit</label>
              <input type="checkbox" class="form-check-input" [(ngModel)]="form.partial_credit">
            </div>
          </div>

          <!-- Display -->
          <div class="ui-section settings-section">
            <h3 class="settings-title"><tui-icon icon="@tui.monitor"></tui-icon> Display</h3>
            <div class="form-group">
              <label>Questions Per Page</label>
              <app-select [options]="questionsPerPageOpts" [searchable]="false" [(ngModel)]="form.questions_per_page" ariaLabel="Questions Per Page" />
            </div>
            <div class="form-group">
              <label>Navigation</label>
              <app-select [options]="navigationOpts" [searchable]="false" [(ngModel)]="form.navigation_type" ariaLabel="Navigation" />
            </div>
            <div class="toggle-row"><label>Shuffle Questions</label><input type="checkbox" class="form-check-input" [(ngModel)]="form.shuffle_questions"></div>
            <div class="toggle-row"><label>Shuffle Options</label><input type="checkbox" class="form-check-input" [(ngModel)]="form.shuffle_options"></div>
            <div class="toggle-row"><label>Show Question Numbers</label><input type="checkbox" class="form-check-input" [(ngModel)]="form.show_question_numbers"></div>
            <div class="toggle-row"><label>Show Progress Bar</label><input type="checkbox" class="form-check-input" [(ngModel)]="form.show_progress_bar"></div>
          </div>

          <!-- Results -->
          <div class="ui-section settings-section">
            <h3 class="settings-title"><tui-icon icon="@tui.check-circle"></tui-icon> Results Display</h3>
            <div class="toggle-row"><label>Show Results</label><input type="checkbox" class="form-check-input" [(ngModel)]="form.show_results"></div>
            <div class="toggle-row" *ngIf="form.show_results"><label>Show Correct Answers</label><input type="checkbox" class="form-check-input" [(ngModel)]="form.show_correct_answers"></div>
            <div class="toggle-row" *ngIf="form.show_results"><label>Show Explanations</label><input type="checkbox" class="form-check-input" [(ngModel)]="form.show_explanations"></div>
            <div class="toggle-row"><label>Show Score</label><input type="checkbox" class="form-check-input" [(ngModel)]="form.show_score"></div>
            <div class="form-group">
              <label>Results Release</label>
              <app-select [options]="releaseOpts" [searchable]="false" [(ngModel)]="form.results_release_type" ariaLabel="Results Release" />
            </div>
            <div class="form-group" *ngIf="form.results_release_type === 'date'">
              <label>Release Date</label>
              <input type="datetime-local" class="form-control" [(ngModel)]="form.results_release_date">
            </div>
          </div>

          <!-- Proctoring -->
          <div class="ui-section settings-section">
            <h3 class="settings-title"><tui-icon icon="@tui.shield"></tui-icon> Proctoring</h3>
            <div class="proctor-options">
              <div class="proctor-option" *ngFor="let p of proctoringLevels" [class.selected]="form.proctoring_level === p.value"
                   (click)="form.proctoring_level = p.value">
                <div class="proctor-option-header">
                  <tui-icon [icon]="p.icon"></tui-icon>
                  <strong>{{ p.label }}</strong>
                </div>
                <p>{{ p.description }}</p>
                <ul *ngIf="p.features.length > 0">
                  <li *ngFor="let f of p.features">{{ f }}</li>
                </ul>
              </div>
            </div>
          </div>

          <!-- Availability -->
          <div class="ui-section settings-section">
            <h3 class="settings-title"><tui-icon icon="@tui.calendar"></tui-icon> Availability</h3>
            <div class="form-group">
              <label>Start Date & Time</label>
              <input type="datetime-local" class="form-control" [(ngModel)]="form.availability_start">
            </div>
            <div class="form-group">
              <label>End Date & Time</label>
              <input type="datetime-local" class="form-control" [(ngModel)]="form.availability_end">
            </div>
            <small class="text-muted">Leave empty for no time restriction</small>
          </div>
        </div>
      </div>

      <!-- ==========================================
           TAB 4: ASSIGNMENTS
           ========================================== -->
      <section class="ui-section tab-content" *ngIf="activeTab === 'assignments' && isEdit">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title">Learner Assignments ({{ assignments.length }})</h2>
            <p class="ui-section__subtitle">Assign learners, departments, teams or branches</p></div>
        </div>
        <div class="ui-toolbar assign-toolbar">
          <div class="ui-filters assign-actions">
            <app-select style="width:180px" [options]="assignTypeOpts" [(ngModel)]="newAssignment.assignment_type" ariaLabel="Assign to" />
            <app-select style="width:220px" [options]="getAssignTargets()" bindLabel="name" bindValue="id" placeholder="Select..."
                        [(ngModel)]="newAssignment.target_id" ariaLabel="Target"
                        *ngIf="newAssignment.assignment_type !== 'all'" />
            <input type="date" class="form-control" [(ngModel)]="newAssignment.due_date"
                   placeholder="Due date (optional)" aria-label="Due date (optional)">
            <button type="button" class="btn btn-primary" (click)="addAssignment()" [disabled]="!canAddAssignment">
              <i class="bi bi-plus-lg"></i> Assign
            </button>
          </div>
        </div>

        <ul class="ui-list assignments-list" *ngIf="assignments.length > 0">
          <li class="assign-row" *ngFor="let a of assignments">
            <div class="assign-info">
              <span class="ui-pill assign-type-badge" [ngClass]="'atype-' + a.assignment_type">{{ a.assignment_type }}</span>
              <span class="assign-name">{{ a.target_name }}</span>
            </div>
            <span class="assign-due" *ngIf="a.due_date">Due: {{ formatDate(a.due_date) }}</span>
            <span class="assign-date">{{ formatDate(a.created_at) }}</span>
            <button type="button" class="btn-ghost text-danger" (click)="removeAssignment(a)" aria-label="Remove">
              <i class="bi bi-x-lg"></i>
            </button>
          </li>
        </ul>
        <div class="ui-state" *ngIf="assignments.length === 0">
          <span class="ui-state__icon"><i class="bi bi-person-plus"></i></span>
          <h3>No assignments yet</h3>
          <p>Assign learners, departments, or teams to this assessment</p>
        </div>
      </section>

      <!-- Question Picker Modal -->
      <div class="modal-overlay" *ngIf="showQuestionPicker" (click)="showQuestionPicker = false">
        <div class="modal-dialog modal-lg" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5><tui-icon icon="@tui.plus-circle"></tui-icon> Add Questions</h5>
            <button class="btn-close" (click)="showQuestionPicker = false"></button>
          </div>
          <div class="modal-body">
            <div class="picker-toolbar">
              <input type="text" class="form-control form-control-sm" placeholder="Search questions..."
                     [(ngModel)]="pickerSearch" (input)="searchQuestions()">
              <app-select size="sm" style="width:200px" [options]="bankOpts" placeholder="Choose a bank…" [(ngModel)]="pickerBank" (selectionChange)="searchQuestions()" ariaLabel="Question bank" />
              <app-select size="sm" style="width:180px" [options]="pickerTypeOpts" placeholder="All Types" [(ngModel)]="pickerType" (selectionChange)="searchQuestions()" ariaLabel="Question type" />
            </div>
            <div class="picker-list">
              <div class="picker-item" *ngFor="let q of availableQuestions" (click)="togglePickerQuestion(q)"
                   [class.picked]="q.is_picked" [class.already-added]="q.is_existing">
                <input type="checkbox" [checked]="q.is_picked" [disabled]="q.is_existing">
                <div class="picker-q-info">
                  <span class="picker-q-type"><tui-icon [icon]="getTypeIcon(q.question_type)"></tui-icon></span>
                  <span class="picker-q-text">{{ q.question_text | slice:0:120 }}</span>
                </div>
                <span class="picker-q-diff">{{ q.difficulty }}</span>
                <span class="picker-q-pts">{{ q.default_points }} pts</span>
              </div>
              <div *ngIf="availableQuestions.length === 0" class="text-center text-muted py-3">No questions found</div>
            </div>
          </div>
          <div class="modal-footer">
            <span class="text-muted small">{{ pickedCount }} selected</span>
            <button type="button" class="btn btn-outline-secondary" (click)="showQuestionPicker = false">Cancel</button>
            <button type="button" class="btn btn-primary" (click)="addPickedQuestions()" [disabled]="pickedCount === 0">
              Add {{ pickedCount }} Question{{ pickedCount !== 1 ? 's' : '' }}
            </button>
          </div>
        </div>
      </div>
    </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    .form-breadcrumb { display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap; }

    /* Type Selector */
    .type-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .type-card { border: 1px solid var(--ui-border); border-radius: var(--ui-radius-card); padding: 16px; cursor: pointer; text-align: center;
      background: var(--ui-surface); transition: border-color .15s ease, box-shadow .15s ease; }
    .type-card:hover { border-color: var(--ui-border-strong); }
    .type-card.selected { border-color: rgba(var(--brand-primary-rgb), .55); box-shadow: 0 0 0 3px rgba(var(--brand-primary-rgb), .1); }
    .type-card tui-icon { color: var(--ui-text-muted); }
    .type-card.selected tui-icon { color: var(--brand-primary); }
    .type-card h4 { font-size: .875rem; font-weight: 600; margin: 6px 0 4px; color: var(--ui-text); }
    .type-card p { font-size: .75rem; color: var(--ui-text-muted); margin: 0; }

    /* Form Layout */
    .form-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 20px; align-items: start; }
    .form-grid > .ui-section { margin-bottom: 0; }
    .form-group { margin-bottom: 14px; }
    .form-group label { font-size: .8125rem; font-weight: 550; margin-bottom: 4px; display: block; color: var(--ui-text); }
    .required { color: var(--ui-danger); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    /* Badges: soft tones */
    .type-badge, .status-badge, .assign-type-badge { height: 22px; font-size: .6875rem; }
    .type-badge, .assign-type-badge { text-transform: uppercase; letter-spacing: .03em; }
    .status-badge { text-transform: capitalize; }
    .type-quiz, .atype-user { background: rgba(var(--brand-primary-rgb), .1); border-color: rgba(var(--brand-primary-rgb), .28); color: var(--brand-primary); }
    .type-exam, .status-closed, .atype-branch { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .type-practice, .status-published, .atype-department { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .type-survey, .atype-team { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .status-draft, .atype-all { background: rgba(var(--ui-neutral-rgb), .08); border-color: rgba(var(--ui-neutral-rgb), .22); color: var(--ui-neutral-text); }

    /* Section Manager */
    .section-info .sep { margin: 0 6px; }
    .section-block { border: 1px solid var(--ui-border); border-radius: var(--ui-radius-card); margin-bottom: 12px; overflow: hidden; }
    .section-header { display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: var(--ui-surface-2);
      cursor: pointer; border-bottom: 1px solid var(--ui-border); }
    .section-drag { color: var(--ui-text-subtle); cursor: grab; }
    .section-title-area { flex: 1; min-width: 0; }
    .section-title-area h4 { font-size: .875rem; font-weight: 600; margin: 0; color: var(--ui-text); }
    .section-count { font-size: .75rem; color: var(--ui-text-muted); }
    .section-edit-area { flex: 1; }
    .section-header-actions { display: flex; align-items: center; gap: 2px; }
    .collapse-icon { color: var(--ui-text-muted); margin-left: 4px; }
    .section-body { padding: 8px; }

    /* Questions */
    .question-row { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: var(--ui-radius-ctl);
      border: 1px solid var(--ui-border); margin-bottom: 4px; font-size: .8125rem; background: var(--ui-surface); }
    .question-row:hover { border-color: var(--ui-border-strong); }
    .q-num { font-weight: 600; color: var(--ui-text-muted); min-width: 24px; text-align: center; font-size: .75rem; }
    .q-type-icon { color: var(--brand-primary); }
    .q-text { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--ui-text); }
    .q-points { display: flex; align-items: center; gap: 4px; font-size: .75rem; color: var(--ui-text-muted); }
    .points-input { width: 52px; height: 28px; padding: 0 4px; border: 1px solid var(--ui-border); border-radius: 6px;
      font-size: .75rem; text-align: center; }
    .points-input:focus { outline: none; border-color: rgba(var(--brand-primary-rgb), .55); box-shadow: 0 0 0 3px rgba(var(--brand-primary-rgb), .12); }
    .q-actions { display: flex; align-items: center; gap: 2px; }
    .move-select { width: 96px; height: 28px; min-height: 28px; font-size: .6875rem; padding: 0 22px 0 6px; }
    .section-empty { text-align: center; padding: 24px; color: var(--ui-text-muted); font-size: .8125rem; }

    /* Settings */
    .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
    .settings-grid > .ui-section { margin-bottom: 0; }
    .settings-title { font-size: 1.0625rem; font-weight: 650; display: flex; align-items: center; gap: 8px; color: var(--ui-text);
      margin: 0 0 16px; }
    .settings-title tui-icon { color: var(--brand-primary); font-size: 1rem; }
    .toggle-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0;
      font-size: .8125rem; border-bottom: 1px solid var(--ui-border); }
    .toggle-row:last-child { border-bottom: 0; }
    .toggle-row label { margin: 0; color: var(--ui-text); }
    .toggle-row .form-check-input { margin: 0; }
    .input-group { display: flex; gap: 0; flex-wrap: nowrap; }
    .input-group .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .input-group .form-select { border-top-left-radius: 0; border-bottom-left-radius: 0; }

    /* Proctoring Options */
    .proctor-options { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .proctor-option { border: 1px solid var(--ui-border); border-radius: var(--ui-radius-card); padding: 12px; cursor: pointer;
      transition: border-color .15s ease, box-shadow .15s ease; }
    .proctor-option:hover { border-color: var(--ui-border-strong); }
    .proctor-option.selected { border-color: rgba(var(--brand-primary-rgb), .55); box-shadow: 0 0 0 3px rgba(var(--brand-primary-rgb), .1); }
    .proctor-option-header { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; font-size: .8125rem; }
    .proctor-option.selected .proctor-option-header tui-icon { color: var(--brand-primary); }
    .proctor-option p { font-size: .75rem; color: var(--ui-text-muted); margin: 0; }
    .proctor-option ul { font-size: .6875rem; color: var(--ui-text-muted); margin: 4px 0 0; padding-left: 16px; }

    /* Assignments */
    .assign-toolbar { margin-bottom: 16px; }
    .assign-row { font-size: .8125rem; }
    .assign-info { flex: 1; display: flex; align-items: center; gap: 8px; min-width: 0; }
    .assign-name { color: var(--ui-text); font-weight: 550; }
    .assign-due, .assign-date { font-size: .75rem; color: var(--ui-text-muted); }

    /* Question Picker Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(16, 24, 18, .4); z-index: 1050; display: flex;
      align-items: center; justify-content: center; }
    .modal-dialog { background: var(--ui-surface); border-radius: var(--ui-radius-panel); max-width: 800px; width: 95%; max-height: 85vh;
      display: flex; flex-direction: column; margin: 0; box-shadow: var(--ui-shadow-pop); }
    .modal-dialog.modal-lg { max-width: 800px; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px;
      border-bottom: 1px solid var(--ui-border); flex-shrink: 0; }
    .modal-header h5 { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 1rem; font-weight: 650; }
    .modal-body { padding: 16px 20px; overflow-y: auto; flex: 1; }
    .modal-footer { display: flex; justify-content: flex-end; align-items: center; gap: 8px; padding: 12px 20px;
      border-top: 1px solid var(--ui-border); flex-shrink: 0; }
    .picker-toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
    .picker-list { max-height: 400px; overflow-y: auto; }
    .picker-item { display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: var(--ui-radius-ctl);
      cursor: pointer; font-size: .8125rem; border: 1px solid transparent; }
    .picker-item:hover { background: var(--ui-surface-2); }
    .picker-item.picked { background: rgba(var(--brand-primary-rgb), .06); border-color: rgba(var(--brand-primary-rgb), .3); }
    .picker-item.already-added { opacity: 0.5; cursor: not-allowed; }
    .picker-q-info { flex: 1; display: flex; align-items: center; gap: 6px; overflow: hidden; }
    .picker-q-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .picker-q-type { color: var(--brand-primary); flex-shrink: 0; }
    .picker-q-diff { font-size: .6875rem; color: var(--ui-text-muted); text-transform: capitalize; }
    .picker-q-pts { font-size: .6875rem; font-weight: 550; }

    .btn-ghost { background: none; border: none; color: var(--ui-text-muted); cursor: pointer; padding: 4px 6px; border-radius: 6px; }
    .btn-ghost:hover:not(:disabled) { background: var(--ui-surface-2); color: var(--ui-text); }
    .btn-ghost:disabled { opacity: .35; }

    @media (max-width: 991.98px) {
      .form-grid, .settings-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 767.98px) {
      .type-cards { grid-template-columns: repeat(2, 1fr); }
      .proctor-options, .form-row { grid-template-columns: 1fr; }
      .picker-toolbar { flex-wrap: wrap; }
    }
  `]
})
export class AssessmentFormComponent implements OnInit, OnDestroy {
  private api = inject(AssessmentAdminService);
  private apiClient = inject(ApiClient);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private dialog = inject(ConfirmDialogService);
  private destroy$ = new Subject<void>();

  isEdit = false;
  loading = false;
  saving = false;
  activeTab = 'details';

  form: AssessmentForm = this.getDefaultForm();
  sections: Section[] = [];
  assignments: Assignment[] = [];
  categories: Category[] = [];
  courses: Course[] = [];
  trainings: Training[] = [];
  gradingScales: GradingScale[] = [];
  banks: QuestionBank[] = [];

  // Question Picker
  showQuestionPicker = false;
  pickerTargetSection: Section | null = null;
  pickerSearch = '';
  pickerBank = '';
  pickerType = '';
  availableQuestions: any[] = [];
  bankOpts: { label: string; value: string }[] = [];
  readonly pickerTypeOpts = [
    { label: 'All Types', value: '' },
    { label: 'Multiple Choice', value: 'multiple_choice' },
    { label: 'Multiple Response', value: 'multiple_response' },
    { label: 'True/False', value: 'true_false' },
    { label: 'Short Answer', value: 'short_answer' },
    { label: 'Long Answer/Essay', value: 'long_answer' },
    { label: 'Numeric', value: 'numeric' },
    { label: 'Fill in Blank', value: 'fill_blank' },
    { label: 'Matching', value: 'matching' },
    { label: 'Ordering', value: 'ordering' },
    { label: 'File Upload', value: 'file_upload' },
    { label: 'Hotspot', value: 'hotspot' },
  ];
  readonly statusOpts = [
    { label: 'Draft', value: 'draft' }, { label: 'Published', value: 'published' }, { label: 'Closed', value: 'closed' },
  ];
  readonly passingTypeOpts = [{ label: 'Percentage', value: 'percentage' }, { label: 'Points', value: 'points' }];
  readonly questionsPerPageOpts = [
    { label: 'One at a time', value: 1 }, { label: '5 per page', value: 5 },
    { label: '10 per page', value: 10 }, { label: 'All on one page', value: 0 },
  ];
  readonly navigationOpts = [
    { label: 'Free navigation', value: 'free' },
    { label: 'Linear (no going back)', value: 'linear' },
    { label: 'Section-by-section', value: 'section' },
  ];
  readonly releaseOpts = [
    { label: 'Immediately', value: 'immediate' },
    { label: 'After assessment closes', value: 'after_close' },
    { label: 'Manual release', value: 'manual' },
    { label: 'On specific date', value: 'date' },
  ];
  readonly assignTypeOpts = [
    { label: 'Individual User', value: 'user' }, { label: 'Department', value: 'department' },
    { label: 'Team', value: 'team' }, { label: 'Branch', value: 'branch' }, { label: 'All Users', value: 'all' },
  ];
  private moveOptsCache = new Map<string, { label: string; value: number; disabled: boolean }[]>();
  private moveOptsSig = '';

  /** Section targets for the per-question "Move..." picker; cached per section index. */
  moveOpts(si: number): { label: string; value: number; disabled: boolean }[] {
    const sig = this.sections.map(x => x.title).join('\u0000') + '|' + this.sections.length;
    if (sig !== this.moveOptsSig) { this.moveOptsSig = sig; this.moveOptsCache.clear(); }
    let opts = this.moveOptsCache.get(String(si));
    if (!opts) {
      opts = this.sections.map((ts, tsi) => ({ label: ts.title || 'Section ' + (tsi + 1), value: tsi, disabled: tsi === si }));
      this.moveOptsCache.set(String(si), opts);
    }
    return opts;
  }

  // Assignments
  newAssignment = { assignment_type: 'user' as string, target_id: '', due_date: '' };
  assignTargets: { users: any[], departments: any[], teams: any[], branches: any[] } = { users: [], departments: [], teams: [], branches: [] };

  assessmentTypes = [
    { value: 'quiz' as const, label: 'Quiz', icon: '@tui.zap', description: 'Quick knowledge checks with auto-grading' },
    { value: 'exam' as const, label: 'Exam', icon: '@tui.file-text', description: 'Formal assessments with proctoring support' },
    { value: 'practice' as const, label: 'Practice', icon: '@tui.refresh-cw', description: 'Unlimited attempts, no scoring pressure' },
    { value: 'survey' as const, label: 'Survey', icon: '@tui.clipboard', description: 'Feedback and opinion collection' }
  ];

  proctoringLevels = [
    { value: 'none' as const, label: 'None', icon: '@tui.shield-off', description: 'No proctoring', features: [] },
    { value: 'basic' as const, label: 'Basic', icon: '@tui.shield', description: 'Browser lockdown',
      features: ['Tab switch detection', 'Copy/paste prevention', 'Right-click block'] },
    { value: 'standard' as const, label: 'Standard', icon: '@tui.shield', description: 'Browser + Webcam',
      features: ['All Basic features', 'Webcam monitoring', 'Face detection', 'Periodic snapshots'] },
    { value: 'advanced' as const, label: 'Advanced', icon: '@tui.shield', description: 'Full monitoring',
      features: ['All Standard features', 'Screen recording', 'ID verification', 'AI behavior analysis'] }
  ];

  get totalQuestionCount(): number { return this.sections.reduce((sum, s) => sum + s.questions.length, 0); }
  get totalPoints(): number { return this.sections.reduce((sum, s) => sum + s.questions.reduce((qs, q) => qs + q.points, 0), 0); }
  get pickedCount(): number { return this.availableQuestions.filter(q => q.is_picked && !q.is_existing).length; }
  get canAddAssignment(): boolean {
    return this.newAssignment.assignment_type === 'all' || !!this.newAssignment.target_id;
  }

  ngOnInit(): void {
    const id = this.route.snapshot.params['id'];
    if (id && id !== 'new' && id !== 'create') {
      this.isEdit = true;
      this.form.assessment_id = id;
      this.loadAssessment(id);
      this.loadAssignments(id);
    }
    this.loadLookups();
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  getDefaultForm(): AssessmentForm {
    return {
      assessment_id: null, title: '', description: '', instructions: '', assessment_type: 'quiz',
      category_id: null, status: 'draft', time_limit_minutes: 0, max_attempts: 0, attempt_cooldown_hours: 0,
      passing_score: 60, passing_type: 'percentage', negative_marking: false, negative_marking_percentage: 25,
      partial_credit: true, questions_per_page: 1, navigation_type: 'free', shuffle_questions: false,
      shuffle_options: false, show_question_numbers: true, show_progress_bar: true, show_results: true,
      show_correct_answers: true, show_explanations: true, show_score: true, results_release_type: 'immediate',
      results_release_date: '', proctoring_level: 'none', availability_start: '', availability_end: '',
      course_id: null, training_id: null, grading_scale_id: null, welcome_message: '', completion_message: ''
    };
  }

  /** REST assessment → the form this screen edits. */
  private apiToForm(a: AssessmentDetail): AssessmentForm {
    return {
      ...this.getDefaultForm(),
      assessment_id: a.id,
      title: a.title,
      description: a.description ?? '',
      instructions: a.instructions ?? '',
      assessment_type: ((a.kind as string) === 'assignment' ? 'practice' : a.kind) as AssessmentForm['assessment_type'],
      category_id: a.categoryId,
      status: (a.status === 'archived' ? 'closed' : a.status) as AssessmentForm['status'],
      time_limit_minutes: a.timeLimitMinutes ?? 0,
      max_attempts: a.maxAttempts ?? 0,
      attempt_cooldown_hours: a.attemptsGapHours,
      passing_score: a.passingScore,
      passing_type: a.passingType,
      negative_marking: a.negativeMarking,
      negative_marking_percentage: a.negativeMarkingPercentage,
      partial_credit: a.partialCredit,
      questions_per_page: a.questionsPerPage,
      navigation_type: a.allowBackwardNavigation ? 'free' : 'linear',
      shuffle_questions: a.shuffleQuestions,
      shuffle_options: a.shuffleOptions,
      show_question_numbers: a.showQuestionNumbers,
      show_progress_bar: a.showProgressBar,
      show_explanations: a.showExplanations,
      show_score: a.showScoreImmediately,
      show_results: a.showScoreImmediately,
      show_correct_answers: a.revealAnswers !== 'never',
      results_release_type: this.revealToRelease(a.revealAnswers),
      proctoring_level: a.proctoringLevel,
      availability_start: a.availableFrom ?? '',
      availability_end: a.availableUntil ?? '',
      course_id: a.courseId,
      training_id: a.trainingId,
      grading_scale_id: a.gradingScaleId,
      welcome_message: a.welcomeMessage ?? '',
      completion_message: a.completionMessage ?? '',
    };
  }

  /** The form → the REST input the API accepts. */
  private formToInput(status: string): AssessmentInput {
    const f = this.form;
    return {
      title: f.title.trim(),
      kind: f.assessment_type,
      description: f.description || null,
      instructions: f.instructions || null,
      categoryId: f.category_id || null,
      courseId: f.course_id || null,
      trainingId: f.training_id || null,
      timeLimitMinutes: f.time_limit_minutes > 0 ? f.time_limit_minutes : null,
      maxAttempts: f.max_attempts > 0 ? f.max_attempts : null,
      attemptsGapHours: f.attempt_cooldown_hours,
      passingScore: f.passing_score,
      passingType: f.passing_type,
      availableFrom: f.availability_start || null,
      availableUntil: f.availability_end || null,
      shuffleQuestions: f.shuffle_questions,
      shuffleOptions: f.shuffle_options,
      allowBackwardNavigation: f.navigation_type === 'free',
      showScoreImmediately: f.show_score,
      showExplanations: f.show_explanations,
      revealAnswers: f.show_correct_answers ? this.releaseToReveal(f.results_release_type) : 'never',
      proctoringLevel: f.proctoring_level,
      negativeMarking: f.negative_marking,
      negativeMarkingPercentage: f.negative_marking_percentage,
      partialCredit: f.partial_credit,
      questionsPerPage: f.questions_per_page,
      showQuestionNumbers: f.show_question_numbers,
      showProgressBar: f.show_progress_bar,
      gradingScaleId: f.grading_scale_id || null,
      // `status` is ignored by create/update; it goes through setStatus.
    } as AssessmentInput;
  }

  private revealToRelease(reveal: string): AssessmentForm['results_release_type'] {
    return reveal === 'immediately' ? 'immediate'
      : reveal === 'after_deadline' ? 'after_close'
      : reveal === 'after_all_attempts' ? 'manual'
      : 'immediate';
  }

  private releaseToReveal(release: string): string {
    return release === 'immediate' ? 'immediately'
      : release === 'after_close' || release === 'date' ? 'after_deadline'
      : 'after_all_attempts';
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadAssessment(id: string): void {
    this.loading = true;
    // The configuration and its questions come from the show response; the
    // sections are their own call. Both are needed before the questions can be
    // grouped under their sections.
    forkJoin({
      detail: this.api.detail(id),
      sections: this.api.sections(id),
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: ({ detail, sections }) => {
        this.form = this.apiToForm(detail);
        this.sections = this.groupQuestions(sections, detail.questions);
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  /** Loads the sections afresh and re-groups the current questions under them. */
  private reloadSections(id: string): void {
    forkJoin({
      detail: this.api.detail(id),
      sections: this.api.sections(id),
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: ({ detail, sections }) => {
        this.sections = this.groupQuestions(sections, detail.questions);
      }
    });
  }

  private groupQuestions(sections: ApiSection[], questions: AttachedQuestion[]): Section[] {
    const mapped: Section[] = sections
      .slice()
      .sort((a, b) => a.sortOrder - b.sortOrder)
      .map(s => ({
        section_id: s.id,
        title: s.title,
        description: s.description ?? '',
        sort_order: s.sortOrder,
        questions: [] as SectionQuestion[],
        is_collapsed: false,
        is_editing: false,
      }));

    // A holding section for questions attached straight to the assessment,
    // with no section of their own — so they are still visible and editable.
    let loose: Section | null = null;
    const byId = new Map(mapped.map(s => [s.section_id, s]));

    for (const q of questions.slice().sort((a, b) => a.sortOrder - b.sortOrder)) {
      const vm: SectionQuestion = {
        aq_id: q.id,
        question_id: q.id,
        question_text: q.text,
        question_type: q.type,
        points: q.pointsInThisAssessment,
        sort_order: q.sortOrder,
        is_required: q.isRequired,
      };
      const target = q.sectionId ? byId.get(q.sectionId) : null;
      if (target) {
        target.questions.push(vm);
      } else {
        loose ??= { section_id: '', title: 'Unsectioned', description: '', sort_order: -1, questions: [], is_collapsed: false, is_editing: false };
        loose.questions.push(vm);
      }
    }

    return loose ? [loose, ...mapped] : mapped;
  }

  loadAssignments(id: string): void {
    this.api.assignments(id).pipe(takeUntil(this.destroy$)).subscribe({
      next: (rows) => {
        this.assignments = rows.map(a => ({
          assignment_id: a.id,
          assignment_type: a.assignmentType,
          target_id: a.targetId,
          target_name: a.targetName ?? '',
          due_date: a.dueDate ?? '',
          created_at: a.createdAt ?? '',
        }));
      }
    });
  }

  loadLookups(): void {
    // Categories are a lookup kind; courses and trainings their own resources.
    this.apiClient.getPaged<{ id: string; name: string }>(Endpoints.lookups.list('category' as any), { perPage: 200 })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (p) => { this.categories = p.items.map(r => ({ category_id: r.id, name: r.name })); }, error: () => undefined,
      });
    this.apiClient.getPaged<{ id: string; title: string }>(Endpoints.courses.list, { perPage: 200 })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (p) => { this.courses = p.items.map(c => ({ id: c.id, title: c.title })); }, error: () => undefined,
      });
    this.apiClient.getPaged<{ id: string; title: string }>(Endpoints.trainings.list, { perPage: 200 })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (p) => { this.trainings = p.items.map(t => ({ id: t.id, title: t.title })); }, error: () => undefined,
      });
    // Grading scales are not a resource yet; the dropdown degrades to none.
    this.gradingScales = [];
    // Question banks, for the picker.
    this.api.banks().pipe(takeUntil(this.destroy$)).subscribe({
      next: (p) => { this.banks = p.items; this.bankOpts = p.items.map(b => ({ label: `${b.name} (${b.questionCount})`, value: b.id })); }, error: () => undefined,
    });
    // Assignment targets — people and the groups.
    this.api.assignmentTargets().pipe(takeUntil(this.destroy$)).subscribe({
      next: (t) => { this.assignTargets = t; }, error: () => undefined,
    });
  }

  // ==========================================
  // SAVE
  // ==========================================

  save(): void { this.saveAssessment(this.form.status); }
  saveDraft(): void { this.saveAssessment('draft'); }
  saveAndPublish(): void { this.saveAssessment('published'); }

  private saveAssessment(status: string): void {
    if (!this.form.title.trim()) return;
    this.saving = true;
    const input = this.formToInput(status);

    const save$ = this.isEdit && this.form.assessment_id
      ? this.api.update(this.form.assessment_id, input)
      : this.api.create(input);

    save$.pipe(takeUntil(this.destroy$)).subscribe({
      next: (saved) => {
        const wasCreate = !this.isEdit;
        this.form.assessment_id = saved.id;
        // Publishing and closing go through the status route, not the save.
        if (status !== saved.status && (status === 'published' || status === 'closed' || status === 'draft')) {
          this.api.setStatus(saved.id, status as any).pipe(takeUntil(this.destroy$)).subscribe({
            next: () => { this.form.status = status as any; },
          });
        } else {
          this.form.status = saved.status as any;
        }
        this.saving = false;
        if (wasCreate) {
          this.isEdit = true;
          this.router.navigate(['/admin/assessments', saved.id, 'edit']);
        }
      },
      error: () => { this.saving = false; }
    });
  }

  // ==========================================
  // SECTIONS
  // ==========================================

  addSection(): void {
    if (!this.isEdit || !this.form.assessment_id) { this.save(); return; }
    const id = this.form.assessment_id;
    this.api.createSection(id, { title: 'Section ' + (this.sections.length + 1), sortOrder: this.sections.length })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (created) => {
          this.sections.push({
            section_id: created.id, title: created.title,
            description: created.description ?? '', sort_order: created.sortOrder,
            questions: [], is_collapsed: false, is_editing: true
          });
        }
      });
  }

  saveSection(s: Section): void {
    if (!this.form.assessment_id || !s.section_id) { s.is_editing = false; return; }
    this.api.updateSection(this.form.assessment_id, s.section_id, { title: s.title, description: s.description || null })
      .pipe(takeUntil(this.destroy$)).subscribe({ next: () => { s.is_editing = false; } });
  }

  async deleteSection(s: Section): Promise<void> {
    if (!this.form.assessment_id || !s.section_id) return;
    if (s.questions.length > 0 && !(await this.dialog.confirm('Delete section with ' + s.questions.length + ' questions?', { type: 'danger' }))) return;
    this.api.deleteSection(this.form.assessment_id, s.section_id)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { this.sections = this.sections.filter(x => x.section_id !== s.section_id); }
      });
  }

  moveSection(index: number, dir: number): void {
    const newIdx = index + dir;
    if (newIdx < 0 || newIdx >= this.sections.length) return;
    [this.sections[index], this.sections[newIdx]] = [this.sections[newIdx], this.sections[index]];
    this.reorderSections();
  }

  reorderSections(): void {
    if (!this.form.assessment_id) return;
    // The holding "Unsectioned" group has no id and is not reordered.
    const ids = this.sections.map(s => s.section_id).filter((id): id is string => !!id);
    this.api.reorderSections(this.form.assessment_id, ids).pipe(takeUntil(this.destroy$)).subscribe();
  }

  // ==========================================
  // QUESTIONS
  // ==========================================

  searchQuestions(): void {
    if (!this.pickerBank) {
      // The REST question search is per-bank; pick one first.
      this.availableQuestions = [];
      return;
    }
    const existingIds = new Set(this.sections.flatMap(s => s.questions.map(q => q.question_id)));
    this.api.bankQuestions(this.pickerBank, {
      search: this.pickerSearch || undefined,
      type: this.pickerType || undefined,
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (paged) => {
        this.availableQuestions = paged.items.map(q => ({
          question_id: q.id,
          question_text: q.text,
          question_type: q.type,
          default_points: q.points,
          is_picked: false,
          is_existing: existingIds.has(q.id),
        }));
      }
    });
  }

  togglePickerQuestion(q: any): void {
    if (q.is_existing) return;
    q.is_picked = !q.is_picked;
  }

  addPickedQuestions(): void {
    const picked = this.availableQuestions.filter(q => q.is_picked && !q.is_existing);
    const id = this.form.assessment_id;
    if (picked.length === 0 || !id) return;

    const targetSection = this.pickerTargetSection || this.sections.find(s => s.section_id) || null;
    const sectionId = targetSection?.section_id || null;

    // Attach each picked question at its bank points, into the target section.
    forkJoin(picked.map(q =>
      this.api.attachQuestion(id, q.question_id, q.default_points ?? null, sectionId)
    )).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.reloadSections(id);
        this.showQuestionPicker = false;
        this.pickerTargetSection = null;
      }
    });
  }

  removeQuestion(s: Section, index: number): void {
    const q = s.questions[index];
    if (!this.form.assessment_id) return;
    this.api.detachQuestion(this.form.assessment_id, q.question_id)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: () => { s.questions.splice(index, 1); } });
  }

  moveQuestion(s: Section, index: number, dir: number): void {
    const newIdx = index + dir;
    if (newIdx < 0 || newIdx >= s.questions.length) return;
    [s.questions[index], s.questions[newIdx]] = [s.questions[newIdx], s.questions[index]];
    this.reorderQuestions(s);
  }

  moveToSection(fromSection: Section, qIndex: number, target: number | null): void {
    if (target == null) return;
    const targetIdx = +target;
    const targetSection = this.sections[targetIdx];
    if (!targetSection || targetSection.section_id === fromSection.section_id || !this.form.assessment_id) return;

    const q = fromSection.questions.splice(qIndex, 1)[0];
    targetSection.questions.push(q);

    this.api.setQuestionSection(this.form.assessment_id, q.question_id, targetSection.section_id || null)
      .pipe(takeUntil(this.destroy$)).subscribe();

  }

  updateQuestionPoints(q: SectionQuestion): void {
    if (!this.form.assessment_id) return;
    this.api.setQuestionPoints(this.form.assessment_id, q.question_id, q.points)
      .pipe(takeUntil(this.destroy$)).subscribe();
  }

  reorderQuestions(s: Section): void {
    if (!this.form.assessment_id) return;
    // Reorder keys on the whole assessment's question order; send this
    // section's order within the flat list the API keeps.
    const order = this.sections.flatMap(sec => sec.questions.map(q => q.question_id));
    this.api.reorderQuestions(this.form.assessment_id, order).pipe(takeUntil(this.destroy$)).subscribe();
  }

  // ==========================================
  // ASSIGNMENTS
  // ==========================================

  getAssignTargets(): { id: string; name: string }[] {
    switch (this.newAssignment.assignment_type) {
      case 'user': return this.assignTargets.users || [];
      case 'department': return this.assignTargets.departments || [];
      case 'team': return this.assignTargets.teams || [];
      case 'branch': return this.assignTargets.branches || [];
      default: return [];
    }
  }

  addAssignment(): void {
    const id = this.form.assessment_id;
    if (!id) return;
    this.api.createAssignment(
      id,
      this.newAssignment.assignment_type as AssignmentType,
      this.newAssignment.assignment_type === 'all' ? null : (this.newAssignment.target_id || null),
      this.newAssignment.due_date || null,
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.loadAssignments(id); this.newAssignment.target_id = ''; }
    });
  }

  removeAssignment(a: Assignment): void {
    if (!this.form.assessment_id) return;
    this.api.deleteAssignment(this.form.assessment_id, a.assignment_id)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { this.assignments = this.assignments.filter(x => x.assignment_id !== a.assignment_id); }
      });
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getSectionPoints(s: Section): number { return s.questions.reduce((sum, q) => sum + q.points, 0); }

  getGlobalQNum(si: number, qi: number): number {
    let count = qi + 1;
    for (let i = 0; i < si; i++) count += this.sections[i].questions.length;
    return count;
  }

  getTypeIcon(type: string): string {
    const icons: Record<string, string> = {
      multiple_choice: '@tui.circle-dot', multiple_response: '@tui.check-square', true_false: '@tui.toggle-left',
      short_answer: '@tui.type', long_answer: '@tui.align-left', numeric: '@tui.hash',
      fill_blank: '@tui.text-cursor-input', matching: '@tui.git-merge', ordering: '@tui.list-ordered',
      file_upload: '@tui.upload', hotspot: '@tui.crosshair'
    };
    return icons[type] || '@tui.help-circle';
  }

  formatDate(d: string): string {
    return d ? new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
  }

  goBack(): void { this.router.navigate(['/admin/assessments']); }
}
