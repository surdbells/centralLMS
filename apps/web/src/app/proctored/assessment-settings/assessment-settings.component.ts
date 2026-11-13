import { Component, OnInit, OnDestroy, Input, Output, EventEmitter, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { SelectComponent } from '../../shared/select/select.component';
import {
  AssessmentAdminService,
  type Assessment,
  type AssessmentInput,
  type PassingType,
  type ProctoringLevel,
} from '../assessment-admin.service';

interface AssessmentSettings {
  // Time & Attempts
  time_limit_minutes: number | null;
  max_attempts: number;
  cooldown_hours: number;
  // Scoring
  passing_score: number;
  passing_score_type: string;
  grading_scale_id: string | null;
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

interface GradingScale { id: string; name: string; description: string; }

@Component({
  selector: 'app-assessment-settings',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent, SelectComponent],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page settings-panel">
      <header class="ui-page-header">
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-sliders"></i></div>
        <div class="ui-page-header__titles">
          <h1>Assessment Settings</h1>
          <p>Timing, scoring, display, results, proctoring and availability</p>
        </div>
        <div class="ui-page-header__actions" *ngIf="showSaveButton">
          <button type="button" class="btn btn-primary" (click)="saveSettings()" [disabled]="saving">
            <i *ngIf="!saving" class="bi bi-floppy"></i>
            <span *ngIf="saving" class="spinner-border spinner-border-sm"></span>
            <span *ngIf="saving">Saving...</span>
            <span *ngIf="!saving">Save Settings</span>
          </button>
        </div>
      </header>

      <!-- Time & Attempts -->
      <section class="ui-section settings-section">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title"><i class="bi bi-clock section-icon"></i> Time & Attempts</h2></div>
        </div>
        <div class="settings-grid">
          <div class="form-group">
            <label class="form-label">Time Limit (minutes)</label>
            <div class="input-with-toggle">
              <input type="number" class="form-control" [(ngModel)]="settings.time_limit_minutes"
                     [disabled]="!hasTimeLimit" min="1" max="600" placeholder="No limit" />
              <label class="toggle-label">
                <input type="checkbox" class="form-check-input" [(ngModel)]="hasTimeLimit"
                       (change)="!hasTimeLimit && (settings.time_limit_minutes = null)" />
                <span>Enable</span>
              </label>
            </div>
            <span class="help-text">Leave empty or disable for unlimited time</span>
          </div>
          <div class="form-group">
            <label class="form-label">Maximum Attempts</label>
            <input type="number" class="form-control" [(ngModel)]="settings.max_attempts" min="1" max="99" />
            <span class="help-text">Number of times a learner can attempt this assessment</span>
          </div>
          <div class="form-group">
            <label class="form-label">Cooldown Period (hours)</label>
            <input type="number" class="form-control" [(ngModel)]="settings.cooldown_hours" min="0" max="720" />
            <span class="help-text">Minimum wait time between attempts (0 for none)</span>
          </div>
        </div>
      </section>

      <!-- Scoring -->
      <section class="ui-section settings-section">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title"><i class="bi bi-bullseye section-icon"></i> Scoring</h2></div>
        </div>
        <div class="settings-grid">
          <div class="form-group">
            <label class="form-label">Passing Score</label>
            <div class="score-group">
              <input type="number" class="form-control" [(ngModel)]="settings.passing_score" min="0"
                     [max]="settings.passing_score_type === 'percentage' ? 100 : 9999" />
              <div class="input-toggle-group">
                <button type="button" class="toggle-btn" [class.active]="settings.passing_score_type === 'percentage'"
                        (click)="settings.passing_score_type = 'percentage'">%</button>
                <button type="button" class="toggle-btn" [class.active]="settings.passing_score_type === 'points'"
                        (click)="settings.passing_score_type = 'points'">Points</button>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Grading Scale</label>
            <app-select [options]="gradingScales" bindLabel="name" bindValue="id" placeholder="None (Pass/Fail only)" [clearable]="true" [(ngModel)]="settings.grading_scale_id" ariaLabel="Grading Scale" />
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
                <input type="number" class="form-control sm" [(ngModel)]="settings.negative_marking_penalty"
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
      </section>

      <!-- Display Options -->
      <section class="ui-section settings-section">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title"><i class="bi bi-display section-icon"></i> Display Options</h2></div>
        </div>
        <div class="settings-grid">
          <div class="form-group">
            <label class="form-label">Questions Per Page</label>
            <app-select [options]="questionsPerPageOpts" [searchable]="false" [(ngModel)]="settings.questions_per_page" ariaLabel="Questions Per Page" />
          </div>
          <div class="form-group">
            <label class="form-label">Navigation Type</label>
            <app-select [options]="navigationOpts" [searchable]="false" [(ngModel)]="settings.navigation_type" ariaLabel="Navigation Type" />
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
      </section>

      <!-- Results Display -->
      <section class="ui-section settings-section">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title"><i class="bi bi-bar-chart section-icon"></i> Results Display</h2></div>
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
            <label class="form-label">Results Release</label>
            <app-select [options]="releaseOpts" [searchable]="false" [(ngModel)]="settings.results_release_type" ariaLabel="Results Release" />
          </div>
          <div class="form-group" *ngIf="settings.show_results && settings.results_release_type === 'date'">
            <label class="form-label">Release Date</label>
            <input type="datetime-local" class="form-control" [(ngModel)]="settings.results_release_date" />
          </div>
        </div>
      </section>

      <!-- Proctoring -->
      <section class="ui-section settings-section">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title"><i class="bi bi-shield-check section-icon"></i> Proctoring</h2>
            <p class="ui-section__subtitle">How closely attempts are monitored</p></div>
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
                <i class="bi bi-check2 feature-check"></i> {{ f }}
              </span>
            </div>
            <div class="procto-check" *ngIf="settings.proctoring_level === level.value">
              <i class="bi bi-check-circle-fill"></i>
            </div>
          </div>
        </div>
      </section>

      <!-- Availability -->
      <section class="ui-section settings-section">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title"><i class="bi bi-calendar-event section-icon"></i> Availability</h2></div>
        </div>
        <div class="settings-grid">
          <div class="form-group">
            <label class="form-label">Start Date & Time</label>
            <input type="datetime-local" class="form-control" [(ngModel)]="settings.start_date" (change)="emitChange()" />
            <span class="help-text">When the assessment becomes available</span>
          </div>
          <div class="form-group">
            <label class="form-label">End Date & Time</label>
            <input type="datetime-local" class="form-control" [(ngModel)]="settings.end_date" (change)="emitChange()" />
            <span class="help-text">When the assessment closes (leave empty for no end date)</span>
          </div>
        </div>
      </section>

      <!-- Save Button (standalone mode) -->
      <div class="settings-footer" *ngIf="showSaveButton">
        <button type="button" class="btn btn-primary" (click)="saveSettings()" [disabled]="saving">
          <i *ngIf="!saving" class="bi bi-floppy"></i>
          <span *ngIf="saving">Saving...</span>
          <span *ngIf="!saving">Save Settings</span>
        </button>
        <span class="ui-pill ui-pill--success" *ngIf="showSaved">
          <i class="bi bi-check2"></i> Settings saved
        </span>
        <span class="ui-pill ui-pill--danger" *ngIf="saveError">
          <i class="bi bi-exclamation-triangle"></i> {{ saveError }}
        </span>
      </div>
    </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    .section-icon { color: var(--brand-primary); margin-right: 6px; font-size: .95rem; }

    .settings-grid {
      display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 20px;
    }
    .form-group { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .form-group.full-width { grid-column: 1 / -1; }
    .form-group .form-label { font-size: .8125rem; font-weight: 550; color: var(--ui-text); margin: 0; }
    .form-control.sm { width: 80px; }
    .help-text { font-size: .75rem; color: var(--ui-text-subtle); }

    .input-with-toggle { display: flex; gap: 12px; align-items: center; }
    .input-with-toggle .form-control { flex: 1; }
    .toggle-label {
      display: flex; align-items: center; gap: 6px; font-size: .8125rem; color: var(--ui-text-muted);
      white-space: nowrap; cursor: pointer; margin: 0;
    }
    .toggle-label .form-check-input { margin: 0; }

    .score-group { display: flex; gap: 0; }
    .score-group .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; flex: 1; }
    .input-toggle-group { display: flex; }
    .toggle-btn {
      height: var(--ui-ctl-h); padding: 0 14px; border: 1px solid var(--ui-border); border-left: none; background: var(--ui-surface-2);
      cursor: pointer; font-size: .8125rem; font-weight: 550; color: var(--ui-text-muted);
    }
    .toggle-btn:last-child { border-radius: 0 var(--ui-radius-ctl) var(--ui-radius-ctl) 0; }
    .toggle-btn.active { background: rgba(var(--brand-primary-rgb), .1); color: var(--brand-primary); }

    /* Toggle Switch */
    .toggle-row { display: flex; align-items: center; gap: 12px; }
    .toggle-row.compact { gap: 8px; font-size: .875rem; color: var(--ui-text); }
    .toggle-switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; margin: 0; }
    .toggle-switch.sm { width: 34px; height: 20px; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; inset: 0; background: var(--ui-border-strong); border-radius: 24px; transition: 0.2s; }
    .slider::before {
      content: ''; position: absolute; height: 16px; width: 16px; left: 3px; bottom: 3px;
      background: #fff; border-radius: 50%; transition: 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,.15);
    }
    .toggle-switch.sm .slider::before { height: 14px; width: 14px; }
    .toggle-switch input:checked + .slider { background: var(--brand-primary); }
    .toggle-switch input:focus-visible + .slider { outline: 2px solid rgba(var(--brand-primary-rgb), .35); outline-offset: 2px; }
    .toggle-switch input:checked + .slider::before { transform: translateX(18px); }
    .toggle-switch.sm input:checked + .slider::before { transform: translateX(14px); }

    .toggle-info { flex: 1; min-width: 0; }
    .toggle-label-text { display: block; font-size: .875rem; font-weight: 550; color: var(--ui-text); }
    .toggle-desc { display: block; font-size: .75rem; color: var(--ui-text-muted); }
    .penalty-input { display: flex; align-items: center; gap: 6px; font-size: .8125rem; color: var(--ui-text-muted); }

    .toggle-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .sub-toggles {
      display: flex; flex-direction: column; gap: 10px; padding-left: 12px;
      border-left: 2px solid rgba(var(--brand-primary-rgb), .2);
    }

    /* Proctoring Cards */
    .proctoring-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .procto-card {
      border: 1px solid var(--ui-border); border-radius: var(--ui-radius-card); padding: 16px;
      cursor: pointer; transition: border-color .15s ease, box-shadow .15s ease; position: relative; background: var(--ui-surface);
    }
    .procto-card:hover { border-color: var(--ui-border-strong); }
    .procto-card.active { border-color: rgba(var(--brand-primary-rgb), .55); box-shadow: 0 0 0 3px rgba(var(--brand-primary-rgb), .1); }
    .procto-icon {
      width: 36px; height: 36px; background: var(--ui-surface-2); border-radius: var(--ui-radius-ctl); color: var(--ui-text-muted);
      display: flex; align-items: center; justify-content: center; margin-bottom: 10px;
    }
    .procto-card.active .procto-icon { background: rgba(var(--brand-primary-rgb), .1); color: var(--brand-primary); }
    .procto-name { display: block; font-size: .875rem; font-weight: 600; color: var(--ui-text); }
    .procto-desc { display: block; font-size: .75rem; color: var(--ui-text-muted); margin-bottom: 8px; }
    .procto-features { display: flex; flex-direction: column; gap: 4px; }
    .procto-feature { display: flex; align-items: center; gap: 6px; font-size: .75rem; color: var(--ui-text-muted); }
    .feature-check { color: var(--ui-success); }
    .procto-check { position: absolute; top: 12px; right: 14px; color: var(--brand-primary); }

    /* Footer */
    .settings-footer { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 4px; }

    @media (max-width: 767.98px) {
      .settings-grid, .proctoring-cards, .toggle-grid { grid-template-columns: 1fr; }
    }
  `]
})
export class AssessmentSettingsComponent implements OnInit, OnDestroy {
  @Input() assessmentId!: string;
  @Input() settings!: AssessmentSettings;
  @Input() showSaveButton = false;
  @Output() settingsChange = new EventEmitter<AssessmentSettings>();

  private admin = inject(AssessmentAdminService);
  private route = inject(ActivatedRoute);
  private destroy$ = new Subject<void>();

  /** The assessment as loaded, so a settings save keeps its other fields. */
  private loaded: Assessment | null = null;

  // No listing endpoint exists for grading scales yet, so the picker only
  // offers "None"; the chosen id still round-trips on the assessment.
  gradingScales: GradingScale[] = [];
  readonly questionsPerPageOpts = [
    { label: '1 (one at a time)', value: 1 }, { label: '5', value: 5 },
    { label: '10', value: 10 }, { label: 'All at once', value: 0 },
  ];
  readonly navigationOpts = [
    { label: 'Free Navigation (jump to any question)', value: 'free' },
    { label: 'Linear (forward only)', value: 'linear' },
    { label: 'Section-based (free within sections)', value: 'section' },
  ];
  readonly releaseOpts = [
    { label: 'Immediately after submission', value: 'immediate' },
    { label: 'After assessment closes', value: 'after_close' },
    { label: 'Manual release by admin', value: 'manual' },
    { label: 'On specific date', value: 'date' },
  ];
  hasTimeLimit = false;
  saving = false;
  showSaved = false;
  saveError: string | null = null;

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

  ngOnInit(): void {
    if (!this.assessmentId) {
      this.assessmentId = this.route.snapshot.paramMap.get('id')
        ?? this.route.parent?.snapshot.paramMap.get('id')
        ?? '';
    }

    if (this.settings) {
      // Embedded: a parent supplied the settings.
      this.hasTimeLimit = !!this.settings.time_limit_minutes;
    } else if (this.assessmentId) {
      // Routed: load the assessment itself and map it in. Its own save button
      // shows, since there is no parent form to save alongside.
      this.showSaveButton = true;
      this.initDefaults();
      this.admin.get(this.assessmentId).pipe(takeUntil(this.destroy$)).subscribe({
        next: (a) => {
          this.loaded = a;
          this.settings = this.fromAssessment(a);
          this.hasTimeLimit = !!this.settings.time_limit_minutes;
        },
        error: () => { this.saveError = 'This assessment could not be loaded.'; },
      });
    } else {
      this.initDefaults();
    }
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

  /** Maps the assessment's own fields onto the settings-form shape. */
  private fromAssessment(a: Assessment): AssessmentSettings {
    return {
      time_limit_minutes: a.timeLimitMinutes,
      max_attempts: a.maxAttempts ?? 1,
      cooldown_hours: a.attemptsGapHours,
      passing_score: a.passingScore,
      passing_score_type: a.passingType,
      grading_scale_id: a.gradingScaleId,
      negative_marking: a.negativeMarking,
      negative_marking_penalty: a.negativeMarkingPercentage,
      allow_partial_credit: a.partialCredit,
      questions_per_page: a.questionsPerPage,
      // The new API models navigation as a single "may go back" flag, so the
      // three-way control collapses to free (back allowed) or linear.
      navigation_type: a.allowBackwardNavigation ? 'free' : 'linear',
      shuffle_questions: a.shuffleQuestions,
      shuffle_options: a.shuffleOptions,
      show_question_numbers: a.showQuestionNumbers,
      show_progress_bar: a.showProgressBar,
      show_results: a.showScoreImmediately || a.revealAnswers !== 'never' || a.showExplanations,
      show_correct_answers: a.revealAnswers !== 'never',
      show_explanations: a.showExplanations,
      show_score: a.showScoreImmediately,
      results_release_type: this.releaseTypeFrom(a),
      results_release_date: '',
      proctoring_level: a.proctoringLevel,
      start_date: this.toLocalInput(a.availableFrom),
      end_date: this.toLocalInput(a.availableUntil),
    };
  }

  private releaseTypeFrom(a: Assessment): string {
    if (a.showScoreImmediately) return 'immediate';
    switch (a.revealAnswers) {
      case 'after_deadline': return 'after_close';
      case 'after_all_attempts': return 'manual';
      default: return 'immediate';
    }
  }

  /** ISO timestamp -> the value a <input type="datetime-local"> expects. */
  private toLocalInput(iso: string | null): string {
    return iso ? iso.slice(0, 16) : '';
  }

  /** When the answer key is shown, from the results controls. */
  private revealAnswersValue(s: AssessmentSettings): string {
    if (!s.show_results || !s.show_correct_answers) return 'never';
    switch (s.results_release_type) {
      case 'after_close': return 'after_deadline';
      case 'manual': return 'after_all_attempts';
      case 'date': return 'after_deadline';
      default: return 'immediately';
    }
  }

  emitChange(): void {
    this.settingsChange.emit({ ...this.settings });
  }

  saveSettings(): void {
    this.saving = true;
    this.saveError = null;
    const s = this.settings;
    const a = this.loaded;

    // A full input built from the loaded assessment, so saving settings never
    // blanks the title, questions or other fields the API also validates.
    const input: AssessmentInput = {
      title: a?.title ?? '',
      kind: a?.kind ?? 'quiz',
      description: a?.description ?? null,
      instructions: a?.instructions ?? null,
      difficulty: a?.difficulty ?? null,
      courseId: a?.courseId ?? null,
      moduleId: a?.moduleId ?? null,
      categoryId: a?.categoryId ?? null,
      trainingId: a?.trainingId ?? null,
      welcomeMessage: a?.welcomeMessage ?? null,
      completionMessage: a?.completionMessage ?? null,
      questionSelection: a?.questionSelection,
      randomQuestionCount: a?.randomQuestionCount ?? null,
      gradingScaleId: s.grading_scale_id,
      timeLimitMinutes: this.hasTimeLimit ? s.time_limit_minutes : null,
      maxAttempts: s.max_attempts,
      attemptsGapHours: s.cooldown_hours,
      passingScore: s.passing_score,
      passingType: s.passing_score_type as PassingType,
      negativeMarking: s.negative_marking,
      negativeMarkingPercentage: s.negative_marking_penalty,
      partialCredit: s.allow_partial_credit,
      questionsPerPage: s.questions_per_page,
      shuffleQuestions: s.shuffle_questions,
      shuffleOptions: s.shuffle_options,
      showQuestionNumbers: s.show_question_numbers,
      showProgressBar: s.show_progress_bar,
      showExplanations: s.show_results && s.show_explanations,
      showScoreImmediately: s.show_results && s.show_score && s.results_release_type === 'immediate',
      revealAnswers: this.revealAnswersValue(s),
      allowBackwardNavigation: s.navigation_type !== 'linear',
      proctoringLevel: s.proctoring_level as ProctoringLevel,
      availableFrom: s.start_date || null,
      availableUntil: s.end_date || null,
    };

    this.admin.update(this.assessmentId, input).pipe(takeUntil(this.destroy$)).subscribe({
      next: (updated) => {
        this.saving = false;
        this.loaded = updated;
        this.showSaved = true;
        setTimeout(() => this.showSaved = false, 3000);
      },
      error: (err) => {
        this.saving = false;
        const e = err as { error?: { error?: { code?: string; message?: string } } };
        this.saveError = e?.error?.error?.message || 'Settings could not be saved.';
      }
    });
  }
}
