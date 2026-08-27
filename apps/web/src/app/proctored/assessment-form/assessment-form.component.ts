// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 10
// Admin: Assessment Form Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

// ==========================================
// INTERFACES
// ==========================================

interface ApiResponse<T> { code: number; status: string; data: T; message: string; }

interface AssessmentForm {
  assessment_id: number | null;
  title: string;
  description: string;
  instructions: string;
  assessment_type: 'quiz' | 'exam' | 'practice' | 'survey';
  category_id: number | null;
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
  course_id: number | null;
  training_id: number | null;
  // Grading
  grading_scale_id: number | null;
  // Messaging
  welcome_message: string;
  completion_message: string;
}

interface Section {
  section_id: number;
  title: string;
  description: string;
  sort_order: number;
  questions: SectionQuestion[];
  is_collapsed: boolean;
  is_editing: boolean;
}

interface SectionQuestion {
  aq_id: number;
  question_id: number;
  question_text: string;
  question_type: string;
  points: number;
  sort_order: number;
  is_required: boolean;
}

interface Assignment {
  assignment_id: number;
  assignment_type: 'user' | 'department' | 'team' | 'branch' | 'all';
  target_id: number;
  target_name: string;
  due_date: string;
  created_at: string;
}

interface Category { category_id: number; name: string; }
interface Course { id: number; title: string; }
interface Training { id: number; title: string; }
interface GradingScale { scale_id: number; name: string; }

@Component({
  selector: 'app-assessment-form',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="assessment-form-container">
      <!-- Header -->
      <div class="form-header">
        <div class="header-left">
          <button class="btn btn-sm btn-secondary" (click)="goBack()">
            <tui-icon icon="@tui.arrow-left"></tui-icon>
          </button>
          <div>
            <h1 class="form-title">{{ isEdit ? 'Edit Assessment' : 'New Assessment' }}</h1>
            <div class="form-breadcrumb" *ngIf="isEdit">
              <span class="type-badge" [ngClass]="'type-' + form.assessment_type">{{ form.assessment_type }}</span>
              <span class="status-badge" [ngClass]="'status-' + form.status">{{ form.status }}</span>
              <span class="text-muted small" *ngIf="form.assessment_id">#{{ form.assessment_id }}</span>
            </div>
          </div>
        </div>
        <div class="header-actions">
          <button class="btn btn-secondary btn-sm" (click)="saveDraft()" [disabled]="saving">
            <tui-icon icon="@tui.save"></tui-icon> Save Draft
          </button>
          <button class="btn btn-primary btn-sm" (click)="saveAndPublish()" [disabled]="saving" *ngIf="form.status !== 'published'">
            <tui-icon icon="@tui.send"></tui-icon> Publish
          </button>
          <button class="btn btn-primary btn-sm" (click)="save()" [disabled]="saving" *ngIf="form.status === 'published'">
            <span class="spinner-border spinner-border-sm me-1" *ngIf="saving"></span> Save Changes
          </button>
        </div>
      </div>

      <!-- Tab Navigation -->
      <div class="tab-nav">
        <button class="tab-btn" [class.active]="activeTab === 'details'" (click)="activeTab = 'details'">
          <tui-icon icon="@tui.file-text"></tui-icon> Details
        </button>
        <button class="tab-btn" [class.active]="activeTab === 'questions'" (click)="activeTab = 'questions'">
          <tui-icon icon="@tui.list-ordered"></tui-icon> Sections & Questions
          <span class="tab-count" *ngIf="totalQuestionCount > 0">{{ totalQuestionCount }}</span>
        </button>
        <button class="tab-btn" [class.active]="activeTab === 'settings'" (click)="activeTab = 'settings'">
          <tui-icon icon="@tui.settings"></tui-icon> Settings
        </button>
        <button class="tab-btn" [class.active]="activeTab === 'assignments'" (click)="activeTab = 'assignments'"
                *ngIf="isEdit">
          <tui-icon icon="@tui.users"></tui-icon> Assignments
          <span class="tab-count" *ngIf="assignments.length > 0">{{ assignments.length }}</span>
        </button>
      </div>

      <!-- ==========================================
           TAB 1: DETAILS
           ========================================== -->
      <div class="tab-content" *ngIf="activeTab === 'details'">
        <!-- Assessment Type Selector -->
        <div class="type-selector" *ngIf="!isEdit">
          <label class="section-label">Assessment Type</label>
          <div class="type-cards">
            <div class="type-card" *ngFor="let t of assessmentTypes" [class.selected]="form.assessment_type === t.value"
                 (click)="form.assessment_type = t.value">
              <tui-icon [icon]="t.icon"></tui-icon>
              <h4>{{ t.label }}</h4>
              <p>{{ t.description }}</p>
            </div>
          </div>
        </div>

        <div class="form-grid">
          <!-- Left Column -->
          <div class="form-col">
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
          </div>

          <!-- Right Column -->
          <div class="form-col sidebar-col">
            <div class="form-group">
              <label>Category</label>
              <select class="form-select" [(ngModel)]="form.category_id">
                <option [value]="null">No Category</option>
                <option *ngFor="let c of categories" [value]="c.category_id">{{ c.name }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>Linked Course</label>
              <select class="form-select" [(ngModel)]="form.course_id">
                <option [value]="null">None</option>
                <option *ngFor="let c of courses" [value]="c.id">{{ c.title }}</option>
              </select>
            </div>
            <div class="form-group">
              <label>Linked Training</label>
              <select class="form-select" [(ngModel)]="form.training_id">
                <option [value]="null">None</option>
                <option *ngFor="let t of trainings" [value]="t.id">{{ t.title }}</option>
              </select>
            </div>
            <div class="form-group" *ngIf="isEdit">
              <label>Status</label>
              <select class="form-select" [(ngModel)]="form.status">
                <option value="draft">Draft</option>
                <option value="published">Published</option>
                <option value="closed">Closed</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- ==========================================
           TAB 2: SECTIONS & QUESTIONS
           ========================================== -->
      <div class="tab-content" *ngIf="activeTab === 'questions'">
        <div class="section-toolbar">
          <div class="section-info">
            <span>{{ sections.length }} Section{{ sections.length !== 1 ? 's' : '' }}</span>
            <span class="sep">·</span>
            <span>{{ totalQuestionCount }} Questions</span>
            <span class="sep">·</span>
            <span>{{ totalPoints }} Points</span>
          </div>
          <div class="section-actions">
            <button class="btn btn-sm btn-primary" (click)="addSection()">
              <tui-icon icon="@tui.plus"></tui-icon> Add Section
            </button>
            <button class="btn btn-sm btn-primary" (click)="showQuestionPicker = true" *ngIf="sections.length > 0">
              <tui-icon icon="@tui.plus-circle"></tui-icon> Add Questions
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
                  <select class="form-select form-select-sm move-select" *ngIf="sections.length > 1"
                          (change)="moveToSection(s, qi, $event)" title="Move to section">
                    <option value="" selected disabled>Move...</option>
                    <option *ngFor="let ts of sections; let tsi = index" [value]="tsi" [disabled]="tsi === si">
                      {{ ts.title || 'Section ' + (tsi + 1) }}
                    </option>
                  </select>
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

        <div class="empty-sections" *ngIf="sections.length === 0">
          <tui-icon icon="@tui.layers"></tui-icon>
          <h5>No sections yet</h5>
          <p class="text-muted">Create a section to start adding questions</p>
          <button class="btn btn-primary" (click)="addSection()">
            <tui-icon icon="@tui.plus"></tui-icon> Add First Section
          </button>
        </div>
      </div>

      <!-- ==========================================
           TAB 3: SETTINGS
           ========================================== -->
      <div class="tab-content" *ngIf="activeTab === 'settings'">
        <div class="settings-grid">
          <!-- Time & Attempts -->
          <div class="settings-section">
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
          <div class="settings-section">
            <h3 class="settings-title"><tui-icon icon="@tui.award"></tui-icon> Scoring</h3>
            <div class="form-group">
              <label>Passing Score</label>
              <div class="input-group">
                <input type="number" class="form-control" [(ngModel)]="form.passing_score" min="0">
                <select class="form-select" [(ngModel)]="form.passing_type" style="max-width: 130px;">
                  <option value="percentage">Percentage</option>
                  <option value="points">Points</option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>Grading Scale</label>
              <select class="form-select" [(ngModel)]="form.grading_scale_id">
                <option [value]="null">Default (A-F)</option>
                <option *ngFor="let g of gradingScales" [value]="g.scale_id">{{ g.name }}</option>
              </select>
            </div>
            <div class="toggle-row">
              <label>Negative Marking</label>
              <input type="checkbox" [(ngModel)]="form.negative_marking">
            </div>
            <div class="form-group" *ngIf="form.negative_marking">
              <label>Penalty Percentage</label>
              <input type="number" class="form-control" [(ngModel)]="form.negative_marking_percentage" min="0" max="100">
            </div>
            <div class="toggle-row">
              <label>Partial Credit</label>
              <input type="checkbox" [(ngModel)]="form.partial_credit">
            </div>
          </div>

          <!-- Display -->
          <div class="settings-section">
            <h3 class="settings-title"><tui-icon icon="@tui.monitor"></tui-icon> Display</h3>
            <div class="form-group">
              <label>Questions Per Page</label>
              <select class="form-select" [(ngModel)]="form.questions_per_page">
                <option [value]="1">One at a time</option>
                <option [value]="5">5 per page</option>
                <option [value]="10">10 per page</option>
                <option [value]="0">All on one page</option>
              </select>
            </div>
            <div class="form-group">
              <label>Navigation</label>
              <select class="form-select" [(ngModel)]="form.navigation_type">
                <option value="free">Free navigation</option>
                <option value="linear">Linear (no going back)</option>
                <option value="section">Section-by-section</option>
              </select>
            </div>
            <div class="toggle-row"><label>Shuffle Questions</label><input type="checkbox" [(ngModel)]="form.shuffle_questions"></div>
            <div class="toggle-row"><label>Shuffle Options</label><input type="checkbox" [(ngModel)]="form.shuffle_options"></div>
            <div class="toggle-row"><label>Show Question Numbers</label><input type="checkbox" [(ngModel)]="form.show_question_numbers"></div>
            <div class="toggle-row"><label>Show Progress Bar</label><input type="checkbox" [(ngModel)]="form.show_progress_bar"></div>
          </div>

          <!-- Results -->
          <div class="settings-section">
            <h3 class="settings-title"><tui-icon icon="@tui.check-circle"></tui-icon> Results Display</h3>
            <div class="toggle-row"><label>Show Results</label><input type="checkbox" [(ngModel)]="form.show_results"></div>
            <div class="toggle-row" *ngIf="form.show_results"><label>Show Correct Answers</label><input type="checkbox" [(ngModel)]="form.show_correct_answers"></div>
            <div class="toggle-row" *ngIf="form.show_results"><label>Show Explanations</label><input type="checkbox" [(ngModel)]="form.show_explanations"></div>
            <div class="toggle-row"><label>Show Score</label><input type="checkbox" [(ngModel)]="form.show_score"></div>
            <div class="form-group">
              <label>Results Release</label>
              <select class="form-select" [(ngModel)]="form.results_release_type">
                <option value="immediate">Immediately</option>
                <option value="after_close">After assessment closes</option>
                <option value="manual">Manual release</option>
                <option value="date">On specific date</option>
              </select>
            </div>
            <div class="form-group" *ngIf="form.results_release_type === 'date'">
              <label>Release Date</label>
              <input type="datetime-local" class="form-control" [(ngModel)]="form.results_release_date">
            </div>
          </div>

          <!-- Proctoring -->
          <div class="settings-section">
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
          <div class="settings-section">
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
      <div class="tab-content" *ngIf="activeTab === 'assignments' && isEdit">
        <div class="assign-toolbar">
          <h3><tui-icon icon="@tui.users"></tui-icon> Learner Assignments ({{ assignments.length }})</h3>
          <div class="assign-actions">
            <select class="form-select form-select-sm" [(ngModel)]="newAssignment.assignment_type">
              <option value="user">Individual User</option>
              <option value="department">Department</option>
              <option value="team">Team</option>
              <option value="branch">Branch</option>
              <option value="all">All Users</option>
            </select>
            <select class="form-select form-select-sm" [(ngModel)]="newAssignment.target_id"
                    *ngIf="newAssignment.assignment_type !== 'all'">
              <option value="">Select...</option>
              <option *ngFor="let t of getAssignTargets()" [value]="t.id">{{ t.name }}</option>
            </select>
            <input type="date" class="form-control form-control-sm" [(ngModel)]="newAssignment.due_date"
                   placeholder="Due date (optional)">
            <button class="btn btn-sm btn-primary" (click)="addAssignment()" [disabled]="!canAddAssignment">
              <tui-icon icon="@tui.plus"></tui-icon> Assign
            </button>
          </div>
        </div>

        <div class="assignments-list" *ngIf="assignments.length > 0">
          <div class="assign-row" *ngFor="let a of assignments">
            <div class="assign-info">
              <span class="assign-type-badge" [ngClass]="'atype-' + a.assignment_type">{{ a.assignment_type }}</span>
              <span class="assign-name">{{ a.target_name }}</span>
            </div>
            <span class="assign-due" *ngIf="a.due_date">Due: {{ formatDate(a.due_date) }}</span>
            <span class="assign-date">{{ formatDate(a.created_at) }}</span>
            <button class="btn btn-sm btn-ghost text-danger" (click)="removeAssignment(a)">
              <tui-icon icon="@tui.x"></tui-icon>
            </button>
          </div>
        </div>
        <div class="empty-sections" *ngIf="assignments.length === 0">
          <tui-icon icon="@tui.user-plus"></tui-icon>
          <h5>No assignments yet</h5>
          <p class="text-muted">Assign learners, departments, or teams to this assessment</p>
        </div>
      </div>

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
              <select class="form-select form-select-sm" [(ngModel)]="pickerBank" (change)="searchQuestions()">
                <option value="">All Banks</option>
                <option *ngFor="let c of categories" [value]="c.category_id">{{ c.name }}</option>
              </select>
              <select class="form-select form-select-sm" [(ngModel)]="pickerType" (change)="searchQuestions()">
                <option value="">All Types</option>
                <option value="multiple_choice">Multiple Choice</option>
                <option value="multiple_response">Multiple Response</option>
                <option value="true_false">True/False</option>
                <option value="short_answer">Short Answer</option>
                <option value="long_answer">Long Answer/Essay</option>
                <option value="numeric">Numeric</option>
                <option value="fill_blank">Fill in Blank</option>
                <option value="matching">Matching</option>
                <option value="ordering">Ordering</option>
                <option value="file_upload">File Upload</option>
                <option value="hotspot">Hotspot</option>
              </select>
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
            <button class="btn btn-secondary" (click)="showQuestionPicker = false">Cancel</button>
            <button class="btn btn-primary" (click)="addPickedQuestions()" [disabled]="pickedCount === 0">
              Add {{ pickedCount }} Question{{ pickedCount !== 1 ? 's' : '' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .assessment-form-container { padding: 1.5rem; max-width: 1200px; }
    .form-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
    .header-left { display: flex; align-items: flex-start; gap: 0.5rem; }
    .form-title { font-size: 1.25rem; font-weight: 700; margin: 0; }
    .form-breadcrumb { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.125rem; }
    .header-actions { display: flex; gap: 0.5rem; }

    /* Tabs */
    .tab-nav { display: flex; gap: 0.125rem; border-bottom: 2px solid #e9ecef; margin-bottom: 1.25rem; }
    .tab-btn { padding: 0.625rem 1rem; border: none; background: none; cursor: pointer; font-size: 0.8125rem;
      font-weight: 500; color: #6c757d; display: flex; align-items: center; gap: 0.375rem; border-bottom: 2px solid transparent;
      margin-bottom: -2px; transition: all 0.2s; }
    .tab-btn:hover { color: #212529; }
    .tab-btn.active { color: #0d6efd; border-bottom-color: #0d6efd; }
    .tab-count { background: #e7f1ff; color: #0d6efd; font-size: 0.625rem; padding: 0.0625rem 0.375rem; border-radius: 1rem; }

    /* Type Selector */
    .section-label { font-weight: 600; margin-bottom: 0.5rem; display: block; font-size: 0.875rem; }
    .type-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem; margin-bottom: 1.25rem; }
    .type-card { border: 2px solid #e9ecef; border-radius: 0.5rem; padding: 1rem; cursor: pointer; text-align: center; transition: all 0.2s; }
    .type-card:hover { border-color: #86b7fe; }
    .type-card.selected { border-color: #0d6efd; background: #f0f6ff; }
    .type-card h4 { font-size: 0.875rem; margin: 0.375rem 0 0.25rem; }
    .type-card p { font-size: 0.6875rem; color: #6c757d; margin: 0; }

    /* Form Layout */
    .form-grid { display: grid; grid-template-columns: 1fr 300px; gap: 1.5rem; }
    .form-group { margin-bottom: 0.875rem; }
    .form-group label { font-size: 0.8125rem; font-weight: 500; margin-bottom: 0.25rem; display: block; }
    .required { color: #dc3545; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
    .sidebar-col { background: #f8f9fa; padding: 1rem; border-radius: 0.5rem; height: fit-content; }

    /* Badges */
    .type-badge { font-size: 0.625rem; padding: 0.125rem 0.5rem; border-radius: 1rem; font-weight: 600; text-transform: uppercase; }
    .type-quiz { background: #cfe2ff; color: #084298; }
    .type-exam { background: #f8d7da; color: #842029; }
    .type-practice { background: #d1e7dd; color: #0f5132; }
    .type-survey { background: #fff3cd; color: #664d03; }
    .status-badge { font-size: 0.6875rem; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-weight: 500; text-transform: capitalize; }
    .status-draft { background: #e2e3e5; color: #495057; }
    .status-published { background: #d1e7dd; color: #0f5132; }
    .status-closed { background: #f8d7da; color: #842029; }

    /* Section Manager */
    .section-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
    .section-info { font-size: 0.8125rem; color: #6c757d; }
    .section-info .sep { margin: 0 0.375rem; }
    .section-actions { display: flex; gap: 0.5rem; }
    .section-block { border: 1px solid #e9ecef; border-radius: 0.5rem; margin-bottom: 0.75rem; overflow: hidden; }
    .section-header { display: flex; align-items: center; gap: 0.5rem; padding: 0.625rem 0.75rem; background: #f8f9fa;
      cursor: pointer; border-bottom: 1px solid #f0f0f0; }
    .section-drag { color: #adb5bd; cursor: grab; }
    .section-title-area { flex: 1; }
    .section-title-area h4 { font-size: 0.875rem; font-weight: 600; margin: 0; }
    .section-count { font-size: 0.6875rem; color: #6c757d; }
    .section-edit-area { flex: 1; }
    .section-header-actions { display: flex; align-items: center; gap: 0.125rem; }
    .collapse-icon { color: #6c757d; margin-left: 0.25rem; }
    .section-body { padding: 0.5rem; }

    /* Questions */
    .question-row { display: flex; align-items: center; gap: 0.5rem; padding: 0.375rem 0.5rem; border-radius: 0.375rem;
      border: 1px solid #f0f0f0; margin-bottom: 0.25rem; font-size: 0.8125rem; }
    .question-row:hover { background: #f8f9fa; }
    .q-num { font-weight: 600; color: #6c757d; min-width: 24px; text-align: center; font-size: 0.75rem; }
    .q-type-icon { color: #6c757d; }
    .q-text { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .q-points { display: flex; align-items: center; gap: 0.25rem; font-size: 0.75rem; color: #6c757d; }
    .points-input { width: 48px; padding: 0.125rem 0.25rem; border: 1px solid #dee2e6; border-radius: 0.25rem;
      font-size: 0.75rem; text-align: center; }
    .q-actions { display: flex; align-items: center; gap: 0.125rem; }
    .move-select { width: 80px; font-size: 0.6875rem; padding: 0.125rem 0.25rem; }
    .section-empty { text-align: center; padding: 1.5rem; color: #6c757d; }
    .empty-sections { text-align: center; padding: 2.5rem; }
    .empty-sections tui-icon { font-size: 2rem; color: #adb5bd; }

    /* Settings */
    .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
    .settings-section { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1rem; }
    .settings-title { font-size: 0.9375rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;
      margin: 0 0 0.875rem; padding-bottom: 0.5rem; border-bottom: 1px solid #f0f0f0; }
    .toggle-row { display: flex; justify-content: space-between; align-items: center; padding: 0.375rem 0;
      font-size: 0.8125rem; }
    .input-group { display: flex; gap: 0; }
    .input-group .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .input-group .form-select { border-top-left-radius: 0; border-bottom-left-radius: 0; }

    /* Proctoring Options */
    .proctor-options { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
    .proctor-option { border: 2px solid #e9ecef; border-radius: 0.375rem; padding: 0.75rem; cursor: pointer; transition: all 0.2s; }
    .proctor-option:hover { border-color: #86b7fe; }
    .proctor-option.selected { border-color: #0d6efd; background: #f0f6ff; }
    .proctor-option-header { display: flex; align-items: center; gap: 0.375rem; margin-bottom: 0.25rem; }
    .proctor-option p { font-size: 0.6875rem; color: #6c757d; margin: 0; }
    .proctor-option ul { font-size: 0.625rem; color: #6c757d; margin: 0.25rem 0 0; padding-left: 1rem; }

    /* Assignments */
    .assign-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem; }
    .assign-toolbar h3 { font-size: 1rem; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .assign-actions { display: flex; gap: 0.375rem; align-items: center; flex-wrap: wrap; }
    .assign-row { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; border: 1px solid #e9ecef;
      border-radius: 0.375rem; margin-bottom: 0.375rem; font-size: 0.8125rem; }
    .assign-info { flex: 1; display: flex; align-items: center; gap: 0.5rem; }
    .assign-type-badge { font-size: 0.5625rem; padding: 0.0625rem 0.375rem; border-radius: 0.25rem; text-transform: uppercase; font-weight: 600; }
    .atype-user { background: #cfe2ff; color: #084298; }
    .atype-department { background: #d1e7dd; color: #0f5132; }
    .atype-team { background: #fff3cd; color: #664d03; }
    .atype-branch { background: #f8d7da; color: #842029; }
    .atype-all { background: #e2e3e5; color: #495057; }
    .assign-due, .assign-date { font-size: 0.75rem; color: #6c757d; }

    /* Question Picker Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1050; display: flex;
      align-items: center; justify-content: center; }
    .modal-dialog { background: #fff; border-radius: 0.75rem; max-width: 800px; width: 95%; max-height: 85vh;
      display: flex; flex-direction: column; }
    .modal-dialog.modal-lg { max-width: 800px; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem;
      border-bottom: 1px solid #e9ecef; flex-shrink: 0; }
    .modal-header h5 { display: flex; align-items: center; gap: 0.5rem; margin: 0; font-size: 1rem; }
    .modal-body { padding: 1rem 1.25rem; overflow-y: auto; flex: 1; }
    .modal-footer { display: flex; justify-content: flex-end; align-items: center; gap: 0.5rem; padding: 0.75rem 1.25rem;
      border-top: 1px solid #e9ecef; flex-shrink: 0; }
    .picker-toolbar { display: flex; gap: 0.5rem; margin-bottom: 0.75rem; }
    .picker-list { max-height: 400px; overflow-y: auto; }
    .picker-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem; border-radius: 0.375rem;
      cursor: pointer; font-size: 0.8125rem; border: 1px solid transparent; }
    .picker-item:hover { background: #f8f9fa; }
    .picker-item.picked { background: #e7f1ff; border-color: #b6d4fe; }
    .picker-item.already-added { opacity: 0.5; cursor: not-allowed; }
    .picker-q-info { flex: 1; display: flex; align-items: center; gap: 0.375rem; overflow: hidden; }
    .picker-q-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .picker-q-type { color: #6c757d; flex-shrink: 0; }
    .picker-q-diff { font-size: 0.6875rem; color: #6c757d; }
    .picker-q-pts { font-size: 0.6875rem; font-weight: 500; }

    .btn-ghost { background: none; border: none; color: #6c757d; cursor: pointer; padding: 0.25rem; border-radius: 0.25rem; }
    .btn-ghost:hover { background: #f0f0f0; color: #212529; }

    @media (max-width: 768px) {
      .form-grid { grid-template-columns: 1fr; }
      .settings-grid { grid-template-columns: 1fr; }
      .type-cards { grid-template-columns: repeat(2, 1fr); }
      .proctor-options { grid-template-columns: 1fr; }
    }
  `]
})
export class AssessmentFormComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
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

  // Question Picker
  showQuestionPicker = false;
  pickerTargetSection: Section | null = null;
  pickerSearch = '';
  pickerBank = '';
  pickerType = '';
  availableQuestions: any[] = [];

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
    if (id && id !== 'new') {
      this.isEdit = true;
      this.loadAssessment(+id);
      this.loadSections(+id);
      this.loadAssignments(+id);
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

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadAssessment(id: number): void {
    this.loading = true;
    const fd = new FormData();
    fd.append('action', 'get_assessment');
    fd.append('assessment_id', id.toString());
    this.http.post<ApiResponse<AssessmentForm>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) this.form = { ...this.getDefaultForm(), ...res.data }; this.loading = false; },
        error: () => { this.loading = false; }
      });
  }

  loadSections(id: number): void {
    const fd = new FormData();
    fd.append('action', 'get_assessment_sections');
    fd.append('assessment_id', id.toString());
    this.http.post<ApiResponse<Section[]>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          if (res.code === 1) this.sections = (res.data || []).map(s => ({ ...s, is_collapsed: false, is_editing: false }));
        }
      });
  }

  loadAssignments(id: number): void {
    const fd = new FormData();
    fd.append('action', 'get_assignments');
    fd.append('assessment_id', id.toString());
    this.http.post<ApiResponse<Assignment[]>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) this.assignments = res.data || []; }
      });
  }

  loadLookups(): void {
    // Categories
    const fd1 = new FormData();
    fd1.append('action', 'get_categories');
    this.http.post<ApiResponse<Category[]>>(`${GlobalComponent.baseURL}/user/question-bank`, fd1)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => { if (res.code === 1) this.categories = res.data || []; } });
    // Courses
    const fd2 = new FormData();
    fd2.append('action', 'get_courses_lookup');
    this.http.post<ApiResponse<Course[]>>(`${GlobalComponent.baseURL}/user/assessment`, fd2)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => { if (res.code === 1) this.courses = res.data || []; } });
    // Trainings
    const fd3 = new FormData();
    fd3.append('action', 'get_trainings_lookup');
    this.http.post<ApiResponse<Training[]>>(`${GlobalComponent.baseURL}/user/assessment`, fd3)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => { if (res.code === 1) this.trainings = res.data || []; } });
    // Grading Scales
    const fd4 = new FormData();
    fd4.append('action', 'get_grading_scales');
    this.http.post<ApiResponse<GradingScale[]>>(`${GlobalComponent.baseURL}/user/assessment`, fd4)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => { if (res.code === 1) this.gradingScales = res.data || []; } });
    // Assignment targets
    const fd5 = new FormData();
    fd5.append('action', 'get_assignment_targets');
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd5)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => { if (res.code === 1) this.assignTargets = res.data; } });
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
    const fd = new FormData();
    fd.append('action', this.isEdit ? 'update_assessment' : 'create_assessment');
    fd.append('status', status);
    Object.keys(this.form).forEach(key => {
      const val = (this.form as any)[key];
      if (val !== null && val !== undefined) fd.append(key, val.toString());
    });

    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          this.saving = false;
          if (res.code === 1) {
            if (!this.isEdit && res.data?.assessment_id) {
              this.router.navigate(['/admin/assessments', res.data.assessment_id, 'edit']);
            }
            this.form.status = status as any;
          }
        },
        error: () => { this.saving = false; }
      });
  }

  // ==========================================
  // SECTIONS
  // ==========================================

  addSection(): void {
    if (!this.isEdit) { this.save(); return; }
    const fd = new FormData();
    fd.append('action', 'create_section');
    fd.append('assessment_id', this.form.assessment_id!.toString());
    fd.append('title', 'Section ' + (this.sections.length + 1));
    fd.append('sort_order', this.sections.length.toString());

    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          if (res.code === 1) {
            this.sections.push({
              section_id: res.data.section_id, title: 'Section ' + (this.sections.length + 1),
              description: '', sort_order: this.sections.length, questions: [], is_collapsed: false, is_editing: true
            });
          }
        }
      });
  }

  saveSection(s: Section): void {
    const fd = new FormData();
    fd.append('action', 'update_section');
    fd.append('section_id', s.section_id.toString());
    fd.append('title', s.title);
    fd.append('description', s.description || '');
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: () => { s.is_editing = false; } });
  }

  deleteSection(s: Section): void {
    if (s.questions.length > 0 && !confirm('Delete section with ' + s.questions.length + ' questions?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_section');
    fd.append('section_id', s.section_id.toString());
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) this.sections = this.sections.filter(x => x.section_id !== s.section_id); }
      });
  }

  moveSection(index: number, dir: number): void {
    const newIdx = index + dir;
    if (newIdx < 0 || newIdx >= this.sections.length) return;
    [this.sections[index], this.sections[newIdx]] = [this.sections[newIdx], this.sections[index]];
    this.reorderSections();
  }

  reorderSections(): void {
    const fd = new FormData();
    fd.append('action', 'reorder_sections');
    fd.append('assessment_id', this.form.assessment_id!.toString());
    fd.append('order', JSON.stringify(this.sections.map((s, i) => ({ section_id: s.section_id, sort_order: i }))));
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe();
  }

  // ==========================================
  // QUESTIONS
  // ==========================================

  searchQuestions(): void {
    const fd = new FormData();
    fd.append('action', 'search_questions_for_assessment');
    fd.append('assessment_id', (this.form.assessment_id || 0).toString());
    if (this.pickerSearch) fd.append('search', this.pickerSearch);
    if (this.pickerBank) fd.append('bank_id', this.pickerBank);
    if (this.pickerType) fd.append('question_type', this.pickerType);

    const existingIds = new Set(this.sections.flatMap(s => s.questions.map(q => q.question_id)));

    this.http.post<ApiResponse<any[]>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          if (res.code === 1) {
            this.availableQuestions = (res.data || []).map(q => ({
              ...q, is_picked: false, is_existing: existingIds.has(q.question_id)
            }));
          }
        }
      });
  }

  togglePickerQuestion(q: any): void {
    if (q.is_existing) return;
    q.is_picked = !q.is_picked;
  }

  addPickedQuestions(): void {
    const picked = this.availableQuestions.filter(q => q.is_picked && !q.is_existing);
    if (picked.length === 0) return;

    const targetSection = this.pickerTargetSection || this.sections[this.sections.length - 1];
    if (!targetSection) return;

    const fd = new FormData();
    fd.append('action', 'bulk_add_questions');
    fd.append('section_id', targetSection.section_id.toString());
    fd.append('questions', JSON.stringify(picked.map(q => ({ question_id: q.question_id, points: q.default_points || 1 }))));

    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          if (res.code === 1) {
            this.loadSections(this.form.assessment_id!);
            this.showQuestionPicker = false;
            this.pickerTargetSection = null;
          }
        }
      });
  }

  removeQuestion(s: Section, index: number): void {
    const q = s.questions[index];
    const fd = new FormData();
    fd.append('action', 'remove_question');
    fd.append('aq_id', q.aq_id.toString());
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) s.questions.splice(index, 1); }
      });
  }

  moveQuestion(s: Section, index: number, dir: number): void {
    const newIdx = index + dir;
    if (newIdx < 0 || newIdx >= s.questions.length) return;
    [s.questions[index], s.questions[newIdx]] = [s.questions[newIdx], s.questions[index]];
    this.reorderQuestions(s);
  }

  moveToSection(fromSection: Section, qIndex: number, event: Event): void {
    const targetIdx = +(event.target as HTMLSelectElement).value;
    const targetSection = this.sections[targetIdx];
    if (!targetSection || targetSection.section_id === fromSection.section_id) return;

    const q = fromSection.questions.splice(qIndex, 1)[0];
    targetSection.questions.push(q);

    const fd = new FormData();
    fd.append('action', 'move_question_to_section');
    fd.append('aq_id', q.aq_id.toString());
    fd.append('section_id', targetSection.section_id.toString());
    fd.append('sort_order', (targetSection.questions.length - 1).toString());
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe();

    (event.target as HTMLSelectElement).value = '';
  }

  updateQuestionPoints(q: SectionQuestion): void {
    const fd = new FormData();
    fd.append('action', 'update_question_points');
    fd.append('aq_id', q.aq_id.toString());
    fd.append('points', q.points.toString());
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe();
  }

  reorderQuestions(s: Section): void {
    const fd = new FormData();
    fd.append('action', 'reorder_questions');
    fd.append('section_id', s.section_id.toString());
    fd.append('order', JSON.stringify(s.questions.map((q, i) => ({ aq_id: q.aq_id, sort_order: i }))));
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe();
  }

  // ==========================================
  // ASSIGNMENTS
  // ==========================================

  getAssignTargets(): { id: number; name: string }[] {
    switch (this.newAssignment.assignment_type) {
      case 'user': return this.assignTargets.users || [];
      case 'department': return this.assignTargets.departments || [];
      case 'team': return this.assignTargets.teams || [];
      case 'branch': return this.assignTargets.branches || [];
      default: return [];
    }
  }

  addAssignment(): void {
    const fd = new FormData();
    fd.append('action', 'create_assignment');
    fd.append('assessment_id', this.form.assessment_id!.toString());
    fd.append('assignment_type', this.newAssignment.assignment_type);
    if (this.newAssignment.target_id) fd.append('target_id', this.newAssignment.target_id);
    if (this.newAssignment.due_date) fd.append('due_date', this.newAssignment.due_date);

    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          if (res.code === 1) { this.loadAssignments(this.form.assessment_id!); this.newAssignment.target_id = ''; }
        }
      });
  }

  removeAssignment(a: Assignment): void {
    const fd = new FormData();
    fd.append('action', 'delete_assignment');
    fd.append('assignment_id', a.assignment_id.toString());
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) this.assignments = this.assignments.filter(x => x.assignment_id !== a.assignment_id); }
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
