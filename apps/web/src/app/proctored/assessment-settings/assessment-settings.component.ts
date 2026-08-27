import { Component, OnInit, OnDestroy, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

interface AssessmentSettings {
  // Time & Attempts
  time_limit_minutes: number | null;
  max_attempts: number;
  cooldown_hours: number;
  // Scoring
  passing_score: number;
  passing_score_type: string;
  grading_scale_id: number | null;
  negative_marking: boolean;
  negative_marking_penalty: number;
  allow_partial_credit: boolean;
  // Display
  questions_per_page: number;
  navigation_type: string;
  shuffle_questions: boolean;
  shuffle_options: boolean;
  show_question_numbers: boolean;
  show_progress_bar: boolean;
  // Results
  show_results: boolean;
  show_correct_answers: boolean;
  show_explanations: boolean;
  show_score: boolean;
  results_release_type: string;
  results_release_date: string;
  // Proctoring
  proctoring_level: string;
  // Availability
  start_date: string;
  end_date: string;
}

interface GradingScale { id: number; name: string; description: string; }
interface ApiResponse<T> { status: string; data: T; message?: string; }

@Component({
  selector: 'app-assessment-settings',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="settings-panel">
      <!-- Time & Attempts -->
      <div class="settings-section">
        <div class="section-title">
          <tui-icon icon="@tui.clock"></tui-icon>
          <h4>Time & Attempts</h4>
        </div>
        <div class="settings-grid">
          <div class="form-group">
            <label>Time Limit (minutes)</label>
            <div class="input-with-toggle">
              <input type="number" class="form-input" [(ngModel)]="settings.time_limit_minutes"
                     [disabled]="!hasTimeLimit" min="1" max="600" placeholder="No limit" />
              <label class="toggle-label">
                <input type="checkbox" [(ngModel)]="hasTimeLimit"
                       (change)="!hasTimeLimit && (settings.time_limit_minutes = null)" />
                <span>Enable</span>
              </label>
            </div>
            <span class="help-text">Leave empty or disable for unlimited time</span>
          </div>
          <div class="form-group">
            <label>Maximum Attempts</label>
            <input type="number" class="form-input" [(ngModel)]="settings.max_attempts" min="1" max="99" />
            <span class="help-text">Number of times a learner can attempt this assessment</span>
          </div>
          <div class="form-group">
            <label>Cooldown Period (hours)</label>
            <input type="number" class="form-input" [(ngModel)]="settings.cooldown_hours" min="0" max="720" />
            <span class="help-text">Minimum wait time between attempts (0 for none)</span>
          </div>
        </div>
      </div>

      <!-- Scoring -->
      <div class="settings-section">
        <div class="section-title">
          <tui-icon icon="@tui.target"></tui-icon>
          <h4>Scoring</h4>
        </div>
        <div class="settings-grid">
          <div class="form-group">
            <label>Passing Score</label>
            <div class="input-group">
              <input type="number" class="form-input" [(ngModel)]="settings.passing_score" min="0"
                     [max]="settings.passing_score_type === 'percentage' ? 100 : 9999" />
              <div class="input-toggle-group">
                <button class="toggle-btn" [class.active]="settings.passing_score_type === 'percentage'"
                        (click)="settings.passing_score_type = 'percentage'">%</button>
                <button class="toggle-btn" [class.active]="settings.passing_score_type === 'points'"
                        (click)="settings.passing_score_type = 'points'">Points</button>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>Grading Scale</label>
            <select class="form-select" [(ngModel)]="settings.grading_scale_id">
              <option [ngValue]="null">None (Pass/Fail only)</option>
              <option *ngFor="let gs of gradingScales" [ngValue]="gs.id">{{ gs.name }}</option>
            </select>
            <span class="help-text">Optional letter grade mapping</span>
          </div>
          <div class="form-group full-width">
            <div class="toggle-row">
              <label class="toggle-switch">
                <input type="checkbox" [(ngModel)]="settings.negative_marking" />
                <span class="slider"></span>
              </label>
              <div class="toggle-info">
                <span class="toggle-label-text">Negative Marking</span>
                <span class="toggle-desc">Deduct points for incorrect answers</span>
              </div>
              <div class="penalty-input" *ngIf="settings.negative_marking">
                <input type="number" class="form-input sm" [(ngModel)]="settings.negative_marking_penalty"
                       min="1" max="100" /> <span>% penalty</span>
              </div>
            </div>
          </div>
          <div class="form-group full-width">
            <div class="toggle-row">
              <label class="toggle-switch">
                <input type="checkbox" [(ngModel)]="settings.allow_partial_credit" />
                <span class="slider"></span>
              </label>
              <div class="toggle-info">
                <span class="toggle-label-text">Partial Credit</span>
                <span class="toggle-desc">Award partial points for partially correct answers (multi-select, matching)</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Display Options -->
      <div class="settings-section">
        <div class="section-title">
          <tui-icon icon="@tui.monitor"></tui-icon>
          <h4>Display Options</h4>
        </div>
        <div class="settings-grid">
          <div class="form-group">
            <label>Questions Per Page</label>
            <select class="form-select" [(ngModel)]="settings.questions_per_page">
              <option [ngValue]="1">1 (one at a time)</option>
              <option [ngValue]="5">5</option>
              <option [ngValue]="10">10</option>
              <option [ngValue]="0">All at once</option>
            </select>
          </div>
          <div class="form-group">
            <label>Navigation Type</label>
            <select class="form-select" [(ngModel)]="settings.navigation_type">
              <option value="free">Free Navigation (jump to any question)</option>
              <option value="linear">Linear (forward only)</option>
              <option value="section">Section-based (free within sections)</option>
            </select>
          </div>
          <div class="form-group full-width">
            <div class="toggle-grid">
              <div class="toggle-row compact">
                <label class="toggle-switch sm">
                  <input type="checkbox" [(ngModel)]="settings.shuffle_questions" />
                  <span class="slider"></span>
                </label>
                <span>Shuffle Questions</span>
              </div>
              <div class="toggle-row compact">
                <label class="toggle-switch sm">
                  <input type="checkbox" [(ngModel)]="settings.shuffle_options" />
                  <span class="slider"></span>
                </label>
                <span>Shuffle Answer Options</span>
              </div>
              <div class="toggle-row compact">
                <label class="toggle-switch sm">
                  <input type="checkbox" [(ngModel)]="settings.show_question_numbers" />
                  <span class="slider"></span>
                </label>
                <span>Show Question Numbers</span>
              </div>
              <div class="toggle-row compact">
                <label class="toggle-switch sm">
                  <input type="checkbox" [(ngModel)]="settings.show_progress_bar" />
                  <span class="slider"></span>
                </label>
                <span>Show Progress Bar</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Results Display -->
      <div class="settings-section">
        <div class="section-title">
          <tui-icon icon="@tui.bar-chart-2"></tui-icon>
          <h4>Results Display</h4>
        </div>
        <div class="settings-grid">
          <div class="form-group full-width">
            <div class="toggle-row">
              <label class="toggle-switch">
                <input type="checkbox" [(ngModel)]="settings.show_results" />
                <span class="slider"></span>
              </label>
              <div class="toggle-info">
                <span class="toggle-label-text">Show Results to Learners</span>
                <span class="toggle-desc">Allow learners to see their assessment results</span>
              </div>
            </div>
          </div>
          <div class="form-group full-width" *ngIf="settings.show_results">
            <div class="sub-toggles">
              <div class="toggle-row compact">
                <label class="toggle-switch sm">
                  <input type="checkbox" [(ngModel)]="settings.show_score" />
                  <span class="slider"></span>
                </label>
                <span>Show Score</span>
              </div>
              <div class="toggle-row compact">
                <label class="toggle-switch sm">
                  <input type="checkbox" [(ngModel)]="settings.show_correct_answers" />
                  <span class="slider"></span>
                </label>
                <span>Show Correct Answers</span>
              </div>
              <div class="toggle-row compact">
                <label class="toggle-switch sm">
                  <input type="checkbox" [(ngModel)]="settings.show_explanations" />
                  <span class="slider"></span>
                </label>
                <span>Show Explanations</span>
              </div>
            </div>
          </div>
          <div class="form-group" *ngIf="settings.show_results">
            <label>Results Release</label>
            <select class="form-select" [(ngModel)]="settings.results_release_type">
              <option value="immediate">Immediately after submission</option>
              <option value="after_close">After assessment closes</option>
              <option value="manual">Manual release by admin</option>
              <option value="date">On specific date</option>
            </select>
          </div>
          <div class="form-group" *ngIf="settings.show_results && settings.results_release_type === 'date'">
            <label>Release Date</label>
            <input type="datetime-local" class="form-input" [(ngModel)]="settings.results_release_date" />
          </div>
        </div>
      </div>

      <!-- Proctoring -->
      <div class="settings-section">
        <div class="section-title">
          <tui-icon icon="@tui.shield"></tui-icon>
          <h4>Proctoring</h4>
        </div>
        <div class="proctoring-cards">
          <div class="procto-card" *ngFor="let level of proctoringLevels"
               [class.active]="settings.proctoring_level === level.value"
               (click)="settings.proctoring_level = level.value; emitChange()">
            <div class="procto-icon">
              <tui-icon [icon]="level.icon"></tui-icon>
            </div>
            <div class="procto-info">
              <span class="procto-name">{{ level.label }}</span>
              <span class="procto-desc">{{ level.description }}</span>
            </div>
            <div class="procto-features" *ngIf="level.features.length > 0">
              <span class="procto-feature" *ngFor="let f of level.features">
                <tui-icon icon="@tui.check" style="font-size:12px;color:#22c55e"></tui-icon> {{ f }}
              </span>
            </div>
            <div class="procto-check" *ngIf="settings.proctoring_level === level.value">
              <tui-icon icon="@tui.check-circle" style="color:#6366f1"></tui-icon>
            </div>
          </div>
        </div>
      </div>

      <!-- Availability -->
      <div class="settings-section">
        <div class="section-title">
          <tui-icon icon="@tui.calendar"></tui-icon>
          <h4>Availability</h4>
        </div>
        <div class="settings-grid">
          <div class="form-group">
            <label>Start Date & Time</label>
            <input type="datetime-local" class="form-input" [(ngModel)]="settings.start_date" (change)="emitChange()" />
            <span class="help-text">When the assessment becomes available</span>
          </div>
          <div class="form-group">
            <label>End Date & Time</label>
            <input type="datetime-local" class="form-input" [(ngModel)]="settings.end_date" (change)="emitChange()" />
            <span class="help-text">When the assessment closes (leave empty for no end date)</span>
          </div>
        </div>
      </div>

      <!-- Save Button (standalone mode) -->
      <div class="settings-footer" *ngIf="showSaveButton">
        <button class="btn-save" (click)="saveSettings()" [disabled]="saving">
          <tui-icon *ngIf="!saving" icon="@tui.save"></tui-icon>
          <span *ngIf="saving">Saving...</span>
          <span *ngIf="!saving">Save Settings</span>
        </button>
        <span class="save-success" *ngIf="showSaved">
          <tui-icon icon="@tui.check" style="color:#22c55e"></tui-icon> Settings saved
        </span>
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .settings-panel { }

    .settings-section {
      background: white; border: 1px solid #e2e8f0; border-radius: 8px;
      padding: 20px; margin-bottom: 16px;
    }
    .section-title {
      display: flex; align-items: center; gap: 8px; margin-bottom: 16px;
      padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;
    }
    .section-title tui-icon { color: #6366f1; font-size: 18px; }
    .section-title h4 { font-size: 15px; font-weight: 600; color: #0f172a; margin: 0; }

    .settings-grid {
      display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px;
    }
    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .form-group.full-width { grid-column: 1 / -1; }
    .form-group label {
      font-size: 13px; font-weight: 500; color: #475569;
    }
    .form-input, .form-select {
      padding: 9px 12px; border: 1px solid #e2e8f0; border-radius: 6px;
      font-size: 14px; color: #0f172a; outline: none; width: 100%;
      background: white; font-family: inherit;
    }
    .form-input:focus, .form-select:focus { border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,0.1); }
    .form-input:disabled { background: #f1f5f9; color: #94a3b8; }
    .form-input.sm { width: 80px; }
    .help-text { font-size: 12px; color: #94a3b8; }

    .input-with-toggle { display: flex; gap: 12px; align-items: center; }
    .input-with-toggle .form-input { flex: 1; }
    .toggle-label {
      display: flex; align-items: center; gap: 6px; font-size: 13px; color: #64748b;
      white-space: nowrap; cursor: pointer;
    }

    .input-group { display: flex; gap: 0; }
    .input-group .form-input { border-radius: 6px 0 0 6px; flex: 1; }
    .input-toggle-group { display: flex; }
    .toggle-btn {
      padding: 9px 14px; border: 1px solid #e2e8f0; background: #f8fafc;
      cursor: pointer; font-size: 13px; font-weight: 500; color: #64748b;
    }
    .toggle-btn:first-child { border-left: none; }
    .toggle-btn:last-child { border-radius: 0 6px 6px 0; }
    .toggle-btn.active { background: #6366f1; color: white; border-color: #6366f1; }

    /* Toggle Switch */
    .toggle-row {
      display: flex; align-items: center; gap: 12px;
    }
    .toggle-row.compact { gap: 8px; font-size: 14px; color: #334155; }
    .toggle-switch {
      position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0;
    }
    .toggle-switch.sm { width: 36px; height: 20px; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .slider {
      position: absolute; cursor: pointer; inset: 0; background: #cbd5e1;
      border-radius: 24px; transition: 0.2s;
    }
    .slider::before {
      content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px;
      background: white; border-radius: 50%; transition: 0.2s;
    }
    .toggle-switch.sm .slider::before { height: 14px; width: 14px; }
    .toggle-switch input:checked + .slider { background: #6366f1; }
    .toggle-switch input:checked + .slider::before { transform: translateX(18px); }
    .toggle-switch.sm input:checked + .slider::before { transform: translateX(16px); }

    .toggle-info { flex: 1; }
    .toggle-label-text { display: block; font-size: 14px; font-weight: 500; color: #0f172a; }
    .toggle-desc { display: block; font-size: 12px; color: #94a3b8; }
    .penalty-input {
      display: flex; align-items: center; gap: 6px; font-size: 13px; color: #64748b;
    }

    .toggle-grid {
      display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
    }
    .sub-toggles {
      display: flex; flex-direction: column; gap: 10px; padding-left: 8px;
      border-left: 3px solid #ede9fe;
    }

    /* Proctoring Cards */
    .proctoring-cards {
      display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
    }
    .procto-card {
      border: 2px solid #e2e8f0; border-radius: 8px; padding: 16px;
      cursor: pointer; transition: all 0.15s; position: relative;
    }
    .procto-card:hover { border-color: #c7d2fe; }
    .procto-card.active { border-color: #6366f1; background: #faf5ff; }
    .procto-icon {
      width: 40px; height: 40px; background: #f1f5f9; border-radius: 8px;
      display: flex; align-items: center; justify-content: center; margin-bottom: 8px;
    }
    .procto-card.active .procto-icon { background: #ede9fe; color: #6366f1; }
    .procto-name { display: block; font-size: 14px; font-weight: 600; color: #0f172a; }
    .procto-desc { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 8px; }
    .procto-features { display: flex; flex-direction: column; gap: 4px; }
    .procto-feature {
      display: flex; align-items: center; gap: 4px; font-size: 12px; color: #475569;
    }
    .procto-check { position: absolute; top: 12px; right: 12px; }

    /* Footer */
    .settings-footer {
      display: flex; align-items: center; gap: 16px; margin-top: 8px;
    }
    .btn-save {
      display: flex; align-items: center; gap: 8px; background: #6366f1; color: white;
      border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600;
      font-size: 14px; cursor: pointer;
    }
    .btn-save:hover { background: #4f46e5; }
    .btn-save:disabled { opacity: 0.5; cursor: default; }
    .save-success {
      display: flex; align-items: center; gap: 6px; font-size: 14px; color: #22c55e; font-weight: 500;
    }

    @media (max-width: 768px) {
      .settings-grid { grid-template-columns: 1fr; }
      .proctoring-cards { grid-template-columns: 1fr; }
      .toggle-grid { grid-template-columns: 1fr; }
    }
  `]
})
export class AssessmentSettingsComponent implements OnInit, OnDestroy {
  @Input() assessmentId!: number;
  @Input() settings!: AssessmentSettings;
  @Input() showSaveButton = false;
  @Output() settingsChange = new EventEmitter<AssessmentSettings>();

  private destroy$ = new Subject<void>();
  private apiUrl = GlobalComponent.baseURL + '/user/assessment';

  gradingScales: GradingScale[] = [];
  hasTimeLimit = false;
  saving = false;
  showSaved = false;

  proctoringLevels = [
    {
      value: 'none', label: 'None', icon: '@tui.shield-off',
      description: 'No proctoring', features: []
    },
    {
      value: 'basic', label: 'Basic', icon: '@tui.shield',
      description: 'Minimal monitoring',
      features: ['Tab switch detection', 'Copy/paste prevention']
    },
    {
      value: 'standard', label: 'Standard', icon: '@tui.shield',
      description: 'Webcam monitoring',
      features: ['Tab switch detection', 'Webcam snapshots', 'Copy/paste prevention']
    },
    {
      value: 'advanced', label: 'Advanced', icon: '@tui.shield',
      description: 'Full proctoring suite',
      features: ['Tab switch detection', 'Webcam recording', 'Screen recording', 'Full-screen lockdown', 'AI violation detection']
    }
  ];

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    if (!this.settings) this.initDefaults();
    this.hasTimeLimit = !!this.settings.time_limit_minutes;
    this.loadGradingScales();
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  private initDefaults(): void {
    this.settings = {
      time_limit_minutes: null, max_attempts: 1, cooldown_hours: 0,
      passing_score: 70, passing_score_type: 'percentage', grading_scale_id: null,
      negative_marking: false, negative_marking_penalty: 25, allow_partial_credit: true,
      questions_per_page: 1, navigation_type: 'free',
      shuffle_questions: false, shuffle_options: false,
      show_question_numbers: true, show_progress_bar: true,
      show_results: true, show_correct_answers: true, show_explanations: true,
      show_score: true, results_release_type: 'immediate', results_release_date: '',
      proctoring_level: 'none', start_date: '', end_date: ''
    };
  }

  loadGradingScales(): void {
    const fd = new FormData();
    fd.append('action', 'get_grading_scales');
    this.http.post<ApiResponse<GradingScale[]>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => { if (res.status === 'success') this.gradingScales = res.data || []; }
    });
  }

  emitChange(): void {
    this.settingsChange.emit({ ...this.settings });
  }

  saveSettings(): void {
    this.saving = true;
    const fd = new FormData();
    fd.append('action', 'update_assessment_settings');
    fd.append('assessment_id', this.assessmentId.toString());
    fd.append('settings', JSON.stringify(this.settings));

    this.http.post<ApiResponse<any>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        this.saving = false;
        if (res.status === 'success') {
          this.showSaved = true;
          setTimeout(() => this.showSaved = false, 3000);
        }
      },
      error: () => { this.saving = false; }
    });
  }
}
