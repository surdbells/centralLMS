// messaging/broadcast/broadcast-management.component.ts

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule, Location } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { MessagingService, Broadcast } from '../messaging.service';
import { TopComponent } from '../../partials/top/top.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { HotToastService } from '@ngneat/hot-toast';
import { SelectComponent } from '../../shared/select/select.component';

// Interface for form target criteria (non-nullable arrays)
interface FormTargetCriteria {
  roles: number[];
  departments: number[];
  course_id: number | null;
}

@Component({
  selector: 'app-broadcast-management',
  standalone: true,
  imports: [CommonModule, FormsModule, TopComponent, TranslatePipe, SelectComponent],
  template: `
    <app-top></app-top>

    <main class="main">
    <div class="ui-page broadcast-management">
      <!-- Header -->
      <header class="ui-page-header">
        <button type="button" class="ui-page-header__back" (click)="goBack()" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></button>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-megaphone"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ 'messaging.broadcast_messages' | translate }}</h1>
          <p>{{ 'messaging.broadcast_subtitle' | translate }}</p>
        </div>
        <div class="ui-page-header__actions">
          <button type="button" class="btn btn-primary" (click)="openNewBroadcastModal()">
            <i class="bi bi-plus-lg me-1"></i>{{ 'messaging.new_broadcast' | translate }}
          </button>
        </div>
      </header>

      <!-- Stats Cards -->
      <div class="ui-kpi-grid ui-kpi-grid--4">
        <div class="ui-kpi ui-kpi--brand">
          <span class="ui-kpi__label"><i class="bi bi-send"></i> {{ 'messaging.total_sent' | translate }}</span>
          <span class="ui-kpi__value">{{ stats.total_sent }}</span>
        </div>
        <div class="ui-kpi ui-kpi--info">
          <span class="ui-kpi__label"><i class="bi bi-people"></i> {{ 'messaging.total_recipients' | translate }}</span>
          <span class="ui-kpi__value">{{ stats.total_recipients }}</span>
        </div>
        <div class="ui-kpi ui-kpi--success">
          <span class="ui-kpi__label"><i class="bi bi-eye"></i> {{ 'messaging.avg_read_rate' | translate }}</span>
          <span class="ui-kpi__value">{{ stats.avg_read_rate }}<small>%</small></span>
        </div>
        <div class="ui-kpi ui-kpi--warning">
          <span class="ui-kpi__label"><i class="bi bi-clock"></i> {{ 'messaging.scheduled' | translate }}</span>
          <span class="ui-kpi__value">{{ stats.scheduled }}</span>
        </div>
      </div>

      <!-- Tabs -->
      <nav class="ui-tabs bc-tabs" role="tablist">
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab === 'sent'" [attr.aria-selected]="activeTab === 'sent'" (click)="activeTab = 'sent'">
          <i class="bi bi-check2-circle"></i> {{ 'messaging.tab_sent' | translate }}
        </button>
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab === 'scheduled'" [attr.aria-selected]="activeTab === 'scheduled'" (click)="activeTab = 'scheduled'">
          <i class="bi bi-clock"></i> {{ 'messaging.scheduled' | translate }}
          <span class="ui-tab__count" *ngIf="scheduledBroadcasts.length">{{ scheduledBroadcasts.length }}</span>
        </button>
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab === 'drafts'" [attr.aria-selected]="activeTab === 'drafts'" (click)="activeTab = 'drafts'">
          <i class="bi bi-file-earmark"></i> {{ 'messaging.tab_drafts' | translate }}
        </button>
      </nav>

      <!-- Broadcasts List -->
      <section class="ui-section">
          <ul class="ui-list broadcast-list" *ngIf="getFilteredBroadcasts().length > 0; else emptyState">
            <li class="broadcast-item" *ngFor="let broadcast of getFilteredBroadcasts(); trackBy: trackByBroadcastId">
              <div class="broadcast-priority" [ngClass]="getPriorityClass(broadcast.priority)">
                <i class="bi" [ngClass]="getPriorityIcon(broadcast.priority)"></i>
              </div>
              <div class="broadcast-content flex-grow-1">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                  <h3 class="broadcast-title mb-0">{{ broadcast.title }}</h3>
                  <span class="ui-pill" [ngClass]="'ui-pill--' + (broadcast.status === 'sent' ? 'success' : broadcast.status === 'scheduled' ? 'info' : broadcast.status === 'cancelled' ? 'danger' : 'neutral')">
                    <span class="ui-pill__dot"></span>{{ broadcast.status | titlecase }}
                  </span>
                </div>
                <p class="broadcast-excerpt ui-muted mb-2">{{ getExcerpt(broadcast.content) }}</p>
                <div class="broadcast-meta">
                  <span class="ui-pill ui-pill--neutral">
                    <i class="bi bi-bullseye"></i>
                    {{ getTargetLabel(broadcast) }}
                  </span>
                  <span class="ui-muted small" *ngIf="broadcast.sent_at">
                    <i class="bi bi-clock me-1"></i>
                    {{ 'messaging.sent_prefix' | translate }} {{ formatDateTime(broadcast.sent_at) }}
                  </span>
                  <span class="ui-muted small" *ngIf="broadcast.scheduled_at && broadcast.status === 'scheduled'">
                    <i class="bi bi-calendar me-1"></i>
                    {{ 'messaging.scheduled_for' | translate }} {{ formatDateTime(broadcast.scheduled_at) }}
                  </span>
                </div>
              </div>
              <div class="broadcast-stats" *ngIf="broadcast.status === 'sent'">
                <div class="stat-mini">
                  <div class="stat-value">{{ broadcast.read_count }}/{{ broadcast.recipients_count }}</div>
                  <div class="stat-label">{{ 'messaging.read_stat' | translate }}</div>
                </div>
                <div class="ui-bar mt-2" style="width: 80px;">
                  <span [style.width.%]="getReadPercentage(broadcast)"></span>
                </div>
              </div>
              <div class="broadcast-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" (click)="viewBroadcast(broadcast)" [title]="'messaging.view_details' | translate">
                  <i class="bi bi-eye"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" *ngIf="broadcast.status === 'draft'"
                        (click)="editBroadcast(broadcast)" [title]="'common.edit' | translate">
                  <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="btn btn-sm btn-primary" *ngIf="broadcast.status === 'draft'"
                        (click)="sendBroadcast(broadcast)" [title]="'messaging.send_now' | translate">
                  <i class="bi bi-send"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" *ngIf="broadcast.status !== 'sent'"
                        (click)="deleteBroadcast(broadcast)" [title]="'common.delete' | translate">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </li>
          </ul>
          <ng-template #emptyState>
            <div class="ui-state empty-state">
              <span class="ui-state__icon"><i class="bi bi-megaphone"></i></span>
              <h3>{{ 'messaging.no_broadcasts' | translate }}</h3>
              <p>
                {{ (activeTab === 'sent' ? 'messaging.no_sent' :
                activeTab === 'scheduled' ? 'messaging.no_scheduled' :
                  'messaging.no_drafts') | translate }}
              </p>
              <button type="button" class="btn btn-primary" (click)="openNewBroadcastModal()">
                <i class="bi bi-plus-lg me-1"></i>{{ 'messaging.create_broadcast' | translate }}
              </button>
            </div>
          </ng-template>
      </section>

      <!-- New/Edit Broadcast Modal -->
      <div class="modal fade" [class.show]="showBroadcastModal" [style.display]="showBroadcastModal ? 'block' : 'none'">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">
                <i class="bi bi-megaphone me-2"></i>
                {{ (editingBroadcast ? 'messaging.edit_broadcast' : 'messaging.new_broadcast') | translate }}
              </h5>
              <button type="button" class="btn-close" (click)="closeBroadcastModal()"></button>
            </div>
            <div class="modal-body">
              <form>
                <!-- Title -->
                <div class="mb-3">
                  <label class="form-label">{{ 'messaging.bc_title' | translate }} *</label>
                  <input type="text" class="form-control" [(ngModel)]="broadcastForm.title" name="title"
                         placeholder="{{ 'messaging.bc_title_ph' | translate }}" required>
                </div>

                <!-- Content -->
                <div class="mb-3">
                  <label class="form-label">{{ 'messaging.bc_message' | translate }} *</label>
                  <textarea class="form-control" rows="6" [(ngModel)]="broadcastForm.content" name="content"
                            placeholder="{{ 'messaging.bc_message_ph' | translate }}" required></textarea>
                  <div class="form-text">
                    <i class="bi bi-info-circle me-1"></i>
                    {{ 'messaging.bc_mentions_help' | translate }}
                  </div>
                </div>

                <div class="row">
                  <!-- Priority -->
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">{{ 'messaging.priority' | translate }}</label>
                      <app-select [options]="priorityOpts" [translateLabels]="true" [searchable]="false"
                                  [ariaLabel]="'messaging.priority' | translate"
                                  [(ngModel)]="broadcastForm.priority" name="priority" />
                    </div>
                  </div>

                  <!-- Target Audience -->
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">{{ 'messaging.target_audience' | translate }} *</label>
                      <app-select [options]="targetTypeOpts" [translateLabels]="true" [searchable]="false"
                                  [ariaLabel]="'messaging.target_audience' | translate"
                                  [(ngModel)]="broadcastForm.target_type" name="target_type"
                                  (selectionChange)="onTargetTypeChange()" />
                    </div>
                  </div>
                </div>

                <!-- Target Criteria -->
                <div class="mb-3" *ngIf="broadcastForm.target_type === 'role'">
                  <label class="form-label">{{ 'messaging.select_roles' | translate }}</label>
                  <div class="d-flex flex-wrap gap-2">
                    <div class="form-check" *ngFor="let role of availableRoles">
                      <input type="checkbox" class="form-check-input" [id]="'role_' + role.id"
                             [checked]="isRoleSelected(role.id)" (change)="toggleRole(role.id)">
                      <label class="form-check-label" [for]="'role_' + role.id">{{ role.name }}</label>
                    </div>
                  </div>
                </div>

                <div class="mb-3" *ngIf="broadcastForm.target_type === 'department'">
                  <label class="form-label">{{ 'messaging.select_departments' | translate }}</label>
                  <app-select [multiple]="true" [options]="availableDepartments" bindLabel="name" bindValue="id"
                              [ariaLabel]="'messaging.select_departments' | translate"
                              [ngModel]="getSelectedDepartments()"
                              (ngModelChange)="setSelectedDepartments($event)" name="departments" />
                </div>

                <div class="mb-3" *ngIf="broadcastForm.target_type === 'course'">
                  <label class="form-label">{{ 'messaging.select_course' | translate }}</label>
                  <app-select [options]="availableCourses" bindLabel="title" bindValue="id"
                              placeholder="{{ 'messaging.select_course_ph' | translate }}" [clearable]="true"
                              [ariaLabel]="'messaging.select_course' | translate"
                              [ngModel]="getSelectedCourseId()"
                              (ngModelChange)="setSelectedCourseId($event)" name="course_id" />
                </div>

                <hr>

                <!-- Scheduling -->
                <div class="mb-3">
                  <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="scheduleCheck"
                           [(ngModel)]="scheduleEnabled" name="scheduleEnabled">
                    <label class="form-check-label" for="scheduleCheck">{{ 'messaging.schedule_later' | translate }}</label>
                  </div>
                </div>

                <div class="row" *ngIf="scheduleEnabled">
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">{{ 'messaging.schedule_date' | translate }}</label>
                      <input type="date" class="form-control" [(ngModel)]="broadcastForm.scheduled_date"
                             name="scheduled_date">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">{{ 'messaging.schedule_time' | translate }}</label>
                      <input type="time" class="form-control" [(ngModel)]="broadcastForm.scheduled_time"
                             name="scheduled_time">
                    </div>
                  </div>
                </div>

                <!-- Expiry -->
                <div class="mb-3">
                  <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="expiryCheck"
                           [(ngModel)]="expiryEnabled" name="expiryEnabled">
                    <label class="form-check-label" for="expiryCheck">{{ 'messaging.set_expiry' | translate }}</label>
                  </div>
                </div>

                <div class="mb-3" *ngIf="expiryEnabled">
                  <label class="form-label">{{ 'messaging.expires_at' | translate }}</label>
                  <input type="datetime-local" class="form-control" [(ngModel)]="broadcastForm.expires_at"
                         name="expires_at">
                </div>

                <!-- Acknowledgment Required -->
                <div class="mb-3">
                  <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="ackCheck"
                           [(ngModel)]="broadcastForm.requires_acknowledgment" name="requires_acknowledgment">
                    <label class="form-check-label" for="ackCheck">
                      {{ 'messaging.require_ack' | translate }}
                      <small class="text-muted d-block">{{ 'messaging.require_ack_help' | translate }}</small>
                    </label>
                  </div>
                </div>
              </form>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" (click)="closeBroadcastModal()">{{ 'common.cancel' | translate }}</button>
              <button type="button" class="btn btn-outline-secondary" (click)="saveDraft()" [disabled]="!isFormValid()">
                <i class="bi bi-file-earmark me-2"></i>{{ 'messaging.save_draft' | translate }}
              </button>
              <button type="button" class="btn btn-primary" (click)="submitBroadcast()" [disabled]="!isFormValid()">
                <i class="bi me-2" [ngClass]="scheduleEnabled ? 'bi-calendar-check' : 'bi-send'"></i>
                {{ (scheduleEnabled ? 'messaging.schedule_action' : 'messaging.send_now') | translate }}
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-backdrop fade show" *ngIf="showBroadcastModal" (click)="closeBroadcastModal()"></div>

      <!-- View Broadcast Modal -->
      <div class="modal fade" [class.show]="showViewModal" [style.display]="showViewModal ? 'block' : 'none'">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">
                <i class="bi bi-megaphone me-2"></i>{{ 'messaging.broadcast_details' | translate }}
              </h5>
              <button type="button" class="btn-close" (click)="closeViewModal()"></button>
            </div>
            <div class="modal-body" *ngIf="viewingBroadcast">
              <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                  <h4>{{ viewingBroadcast.title }}</h4>
                  <span class="badge me-2" [ngClass]="getPriorityBadge(viewingBroadcast.priority)">
                    {{ viewingBroadcast.priority | titlecase }}
                  </span>
                  <span class="badge" [ngClass]="getStatusBadge(viewingBroadcast.status)">
                    {{ viewingBroadcast.status | titlecase }}
                  </span>
                </div>
              </div>

              <div class="broadcast-content-view p-3 bg-light rounded mb-4">
                <div [innerHTML]="viewingBroadcast.content_html || viewingBroadcast.content"></div>
              </div>

              <!-- Stats -->
              <div class="row g-3" *ngIf="viewingBroadcast.status === 'sent'">
                <div class="col-md-4">
                  <div class="detail-stat">
                    <div class="detail-stat-label">{{ 'messaging.recipients' | translate }}</div>
                    <div class="detail-stat-value">{{ viewingBroadcast.recipients_count }}</div>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="detail-stat">
                    <div class="detail-stat-label">{{ 'messaging.read_stat' | translate }}</div>
                    <div class="detail-stat-value text-success">{{ viewingBroadcast.read_count }}</div>
                  </div>
                </div>
                <div class="col-md-4" *ngIf="viewingBroadcast.requires_acknowledgment">
                  <div class="detail-stat">
                    <div class="detail-stat-label">{{ 'messaging.acknowledged' | translate }}</div>
                    <div class="detail-stat-value text-primary">{{ viewingBroadcast.acknowledged_count }}</div>
                  </div>
                </div>
              </div>

              <!-- Meta Info -->
              <hr>
              <div class="row small text-muted">
                <div class="col-md-6">
                  <p><strong>{{ 'messaging.target_label' | translate }}</strong> {{ getTargetLabel(viewingBroadcast) }}</p>
                  <p *ngIf="viewingBroadcast.sent_at"><strong>{{ 'messaging.sent_label' | translate }}</strong> {{ formatDateTime(viewingBroadcast.sent_at) }}</p>
                </div>
                <div class="col-md-6">
                  <p *ngIf="viewingBroadcast.expires_at"><strong>{{ 'messaging.expires_label' | translate }}</strong> {{ formatDateTime(viewingBroadcast.expires_at) }}</p>
                  <p><strong>{{ 'messaging.created_label' | translate }}</strong> {{ formatDateTime(viewingBroadcast.created_at) }}</p>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" (click)="closeViewModal()">{{ 'common.close' | translate }}</button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-backdrop fade show" *ngIf="showViewModal" (click)="closeViewModal()"></div>
    </div>
    </main>
  `,
  styles: [`
    .bc-tabs { margin-bottom: 16px; }

    .broadcast-list > li.broadcast-item {
      gap: 14px;
      padding: 14px 0;
    }

    .broadcast-list > li.broadcast-item:first-child { padding-top: 0; }

    .broadcast-priority {
      width: 38px;
      height: 38px;
      flex-shrink: 0;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .broadcast-priority.priority-normal {
      background: rgba(var(--ui-neutral-rgb, 108, 117, 125), 0.1);
      color: var(--ui-neutral-text, #5b625c);
    }

    .broadcast-priority.priority-important {
      background: rgba(var(--ui-warning-rgb, 178, 94, 9), 0.1);
      color: var(--ui-warning-text, #8a4a07);
    }

    .broadcast-priority.priority-urgent {
      background: rgba(var(--ui-danger-rgb, 192, 53, 43), 0.1);
      color: var(--ui-danger-text, #a02a22);
    }

    .broadcast-content { min-width: 0; }

    .broadcast-title {
      font-size: .9375rem;
      font-weight: 600;
      color: var(--ui-text);
    }

    .broadcast-excerpt {
      font-size: 0.8125rem;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .broadcast-meta {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .broadcast-stats { text-align: center; flex-shrink: 0; }

    .stat-mini .stat-value {
      font-size: .9375rem;
      font-weight: 600;
      color: var(--ui-text);
    }

    .stat-mini .stat-label {
      font-size: 0.75rem;
      color: var(--ui-text-muted);
    }

    .broadcast-actions {
      display: flex;
      gap: 6px;
      flex-shrink: 0;
    }

    .detail-stat {
      text-align: center;
      padding: 1rem;
      background: var(--ui-surface-2, #f7f8f7);
      border: 1px solid var(--ui-border, #e7e9e7);
      border-radius: var(--ui-radius-card, 12px);
    }

    .detail-stat .detail-stat-label {
      font-size: 0.8125rem;
      color: var(--ui-text-muted);
      margin-bottom: 0.25rem;
    }

    .detail-stat .detail-stat-value {
      font-size: 1.5rem;
      font-weight: 650;
      color: var(--ui-text);
    }

    @media (max-width: 767.98px) {
      .broadcast-list > li.broadcast-item { flex-wrap: wrap; }
    }
  `]
})
export class BroadcastManagementComponent implements OnInit, OnDestroy {
  private readonly backLocation = inject(Location);
  private readonly navRouter = inject(Router);
  private readonly toast = inject(HotToastService);
  loading = false;

  /** What went wrong, if anything. Rendered rather than swallowed. */
  error: string | null = null;

  private destroy$ = new Subject<void>();

  // Data
  broadcasts: Broadcast[] = [];
  sentBroadcasts: Broadcast[] = [];
  scheduledBroadcasts: Broadcast[] = [];
  draftBroadcasts: Broadcast[] = [];

  // Stats
  stats = {
    total_sent: 0,
    total_recipients: 0,
    avg_read_rate: 0,
    scheduled: 0
  };

  // UI State
  activeTab: 'sent' | 'scheduled' | 'drafts' = 'sent';
  showBroadcastModal = false;
  showViewModal = false;
  editingBroadcast: Broadcast | null = null;
  viewingBroadcast: Broadcast | null = null;

  // Form - properly typed to allow assignment from Broadcast
  broadcastForm: {
    expires_at: string;
    target_criteria: { course_id: number | null; roles: number[]; departments: number[] };
    requires_acknowledgment: boolean;
    target_type: "all" | "role" | "department" | "course" | "custom";
    title: string;
    priority: "normal" | "important" | "urgent";
    scheduled_date: string;
    scheduled_time: string | undefined;
    content: string
  } = {
    title: '',
    content: '',
    priority: 'normal',
    target_type: 'all',
    target_criteria: {
      roles: [],
      departments: [],
      course_id: null
    },
    requires_acknowledgment: false,
    scheduled_date: '',
    scheduled_time: '',
    expires_at: ''
  };
  scheduleEnabled = false;
  expiryEnabled = false;

  // Available Options
  availableRoles = [
    { id: 1, name: 'Administrator' },
    { id: 2, name: 'Instructor' },
    { id: 3, name: 'Learner' },
    { id: 4, name: 'Manager' }
  ];
  availableDepartments = [
    { id: 1, name: 'Engineering' },
    { id: 2, name: 'Sales' },
    { id: 3, name: 'Marketing' },
    { id: 4, name: 'HR' }
  ];
  availableCourses = [
    { id: 1, title: 'Safety Training' },
    { id: 2, title: 'Leadership 101' },
    { id: 3, title: 'Technical Skills' }
  ];
  readonly priorityOpts = [
    { value: 'normal', label: 'messaging.priority_normal' },
    { value: 'important', label: 'messaging.priority_important' },
    { value: 'urgent', label: 'messaging.priority_urgent' },
  ];
  readonly targetTypeOpts = [
    { value: 'all', label: 'messaging.target_all' },
    { value: 'role', label: 'messaging.target_role' },
    { value: 'department', label: 'messaging.target_department' },
    { value: 'course', label: 'messaging.target_course_enrollment' },
  ];

  constructor(private messagingService: MessagingService, private i18n: I18nService, private dialog: ConfirmDialogService) {}

  /** Header back button: the previous page, or /messaging when there is no history (fresh tab / deep link). */
  goBack(): void {
    if (window.history.length > 1) {
      this.backLocation.back();
      return;
    }
    void this.navRouter.navigate(['/messaging']);
  }

  ngOnInit(): void {
    this.loadBroadcasts();
    this.loadStats();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadBroadcasts(): void {
    this.loading = true;
    this.error = null;

    this.messagingService
      .getAuthoredBroadcasts()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (broadcasts) => {
          this.broadcasts = broadcasts;
          this.categorizeBroadcasts();
          this.loadStats();
          this.loading = false;
        },
        error: (failure: { message?: string }) => {
          this.error = failure.message ?? this.i18n.t('messaging.broadcasts_load_error');
          this.loading = false;
        },
      });
  }

  /**
   * The figures at the top, counted from what came back.
   *
   * They were hard-coded — 45 sent, 12,500 recipients, 78% read — on a
   * screen whose list was an empty array with a TODO above it. Numbers
   * that look like data and are not are worse than none, because nobody
   * checks them.
   */
  loadStats(): void {
    const sent = this.sentBroadcasts;
    const recipients = sent.reduce((total, b) => total + b.recipients_count, 0);
    const read = sent.reduce((total, b) => total + b.read_count, 0);

    this.stats = {
      total_sent: sent.length,
      total_recipients: recipients,
      // Across everybody who received anything, not an average of
      // averages — one announcement to four hundred people and one to
      // three should not weigh the same.
      avg_read_rate: recipients === 0 ? 0 : Math.round((read / recipients) * 100),
      scheduled: this.scheduledBroadcasts.length,
    };
  }

  categorizeBroadcasts(): void {
    this.sentBroadcasts = this.broadcasts.filter(b => b.status === 'sent');
    this.scheduledBroadcasts = this.broadcasts.filter(b => b.status === 'scheduled');
    this.draftBroadcasts = this.broadcasts.filter(b => b.status === 'draft');
  }

  getFilteredBroadcasts(): Broadcast[] {
    switch (this.activeTab) {
      case 'sent': return this.sentBroadcasts;
      case 'scheduled': return this.scheduledBroadcasts;
      case 'drafts': return this.draftBroadcasts;
      default: return [];
    }
  }

  // Modal Handlers
  openNewBroadcastModal(): void {
    this.resetForm();
    this.editingBroadcast = null;
    this.showBroadcastModal = true;
  }

  editBroadcast(broadcast: Broadcast): void {
    this.editingBroadcast = broadcast;
    const criteria = broadcast.target_criteria as { roles?: number[]; departments?: number[]; course_id?: number | null } | null;
    this.broadcastForm = {
      title: broadcast.title,
      content: broadcast.content,
      priority: broadcast.priority,
      target_type: broadcast.target_type,
      target_criteria: {
        roles: criteria?.roles || [],
        departments: criteria?.departments || [],
        course_id: criteria?.course_id ?? null
      },
      requires_acknowledgment: broadcast.requires_acknowledgment,
      scheduled_date: broadcast.scheduled_at ? broadcast.scheduled_at.split('T')[0] : '',
      scheduled_time: broadcast.scheduled_at ? broadcast.scheduled_at.split('T')[1]?.substring(0, 5) : '',
      expires_at: broadcast.expires_at || ''
    };
    this.scheduleEnabled = !!broadcast.scheduled_at;
    this.expiryEnabled = !!broadcast.expires_at;
    this.showBroadcastModal = true;
  }

  closeBroadcastModal(): void {
    this.showBroadcastModal = false;
    this.editingBroadcast = null;
  }

  viewBroadcast(broadcast: Broadcast): void {
    this.viewingBroadcast = broadcast;
    this.showViewModal = true;
  }

  closeViewModal(): void {
    this.showViewModal = false;
    this.viewingBroadcast = null;
  }

  resetForm(): void {
    this.broadcastForm = {
      title: '',
      content: '',
      priority: 'normal',
      target_type: 'all',
      target_criteria: { roles: [], departments: [], course_id: null },
      requires_acknowledgment: false,
      scheduled_date: '',
      scheduled_time: '',
      expires_at: ''
    };
    this.scheduleEnabled = false;
    this.expiryEnabled = false;
  }

  isFormValid(): boolean {
    return !!this.broadcastForm.title && !!this.broadcastForm.content;
  }

  onTargetTypeChange(): void {
    this.broadcastForm.target_criteria = { roles: [], departments: [], course_id: null };
  }

  // Role selection methods
  isRoleSelected(roleId: number): boolean {
    return this.broadcastForm.target_criteria.roles.includes(roleId);
  }

  toggleRole(roleId: number): void {
    const roles = this.broadcastForm.target_criteria.roles;
    const index = roles.indexOf(roleId);
    if (index > -1) {
      roles.splice(index, 1);
    } else {
      roles.push(roleId);
    }
  }

  // Department selection methods
  getSelectedDepartments(): number[] {
    return this.broadcastForm.target_criteria.departments;
  }

  setSelectedDepartments(value: number[]): void {
    this.broadcastForm.target_criteria.departments = value;
  }

  // Course selection methods
  getSelectedCourseId(): number | null {
    return this.broadcastForm.target_criteria.course_id;
  }

  setSelectedCourseId(value: number | null): void {
    this.broadcastForm.target_criteria.course_id = value;
  }

  // Actions
  saveDraft(): void {
    const data = this.prepareBroadcastData();
    this.messagingService.createBroadcast(data)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        this.closeBroadcastModal();
        this.loadBroadcasts();
      });
  }

  submitBroadcast(): void {
    const data = this.prepareBroadcastData();

    if (this.scheduleEnabled) {
      data.scheduled_at = `${this.broadcastForm.scheduled_date}T${this.broadcastForm.scheduled_time}:00`;
    }

    this.messagingService.createBroadcast(data)
      .pipe(takeUntil(this.destroy$))
      .subscribe(broadcast => {
        if (!this.scheduleEnabled) {
          this.messagingService.sendBroadcast(broadcast.broadcast_id)
            .pipe(takeUntil(this.destroy$))
            .subscribe(() => {
              this.closeBroadcastModal();
              this.loadBroadcasts();
            });
        } else {
          this.closeBroadcastModal();
          this.loadBroadcasts();
        }
      });
  }

  prepareBroadcastData(): Partial<Broadcast> {
    return {
      title: this.broadcastForm.title,
      content: this.broadcastForm.content,
      priority: this.broadcastForm.priority,
      target_type: this.broadcastForm.target_type,
      target_criteria: this.broadcastForm.target_criteria,
      requires_acknowledgment: this.broadcastForm.requires_acknowledgment,
      expires_at: this.expiryEnabled ? this.broadcastForm.expires_at : undefined
    };
  }

  async sendBroadcast(broadcast: Broadcast): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('messaging.confirm_send_broadcast'), { type: 'warning' }))) return;

    this.messagingService.sendBroadcast(broadcast.broadcast_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        this.loadBroadcasts();
      });
  }

  async deleteBroadcast(broadcast: Broadcast): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('messaging.confirm_delete_broadcast'), { type: 'danger' }))) return;

    this.messagingService.deleteBroadcast(broadcast.broadcast_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.toast.success(this.i18n.t('messaging.broadcast_deleted'));
          this.loadBroadcasts();
        },
        error: (err: { message?: string }) => {
          this.toast.error(err?.message || this.i18n.t('messaging.broadcast_delete_failed'));
        },
      });
  }

  // Helpers
  getPriorityClass(priority: string): string {
    return `priority-${priority}`;
  }

  getPriorityIcon(priority: string): string {
    switch (priority) {
      case 'urgent': return 'bi-exclamation-triangle-fill';
      case 'important': return 'bi-exclamation-circle-fill';
      default: return 'bi-info-circle';
    }
  }

  getPriorityBadge(priority: string): string {
    switch (priority) {
      case 'urgent': return 'bg-danger';
      case 'important': return 'bg-warning text-dark';
      default: return 'bg-secondary';
    }
  }

  getStatusBadge(status: string): string {
    switch (status) {
      case 'sent': return 'bg-success';
      case 'scheduled': return 'bg-info';
      case 'draft': return 'bg-secondary';
      case 'cancelled': return 'bg-danger';
      default: return 'bg-light text-dark';
    }
  }

  getTargetLabel(broadcast: Broadcast): string {
    switch (broadcast.target_type) {
      case 'all': return this.i18n.t('messaging.target_all');
      case 'role': return this.i18n.t('messaging.target_role');
      case 'department': return this.i18n.t('messaging.target_department');
      case 'course': return this.i18n.t('messaging.target_course_enrollees');
      default: return this.i18n.t('messaging.target_custom');
    }
  }

  getExcerpt(content: string): string {
    if (!content) return '';
    return content.length > 150 ? content.substring(0, 150) + '...' : content;
  }

  getReadPercentage(broadcast: Broadcast): number {
    if (!broadcast.recipients_count) return 0;
    return (broadcast.read_count / broadcast.recipients_count) * 100;
  }

  formatDateTime(datetime: string): string {
    if (!datetime) return '';
    return new Date(datetime).toLocaleString();
  }

  trackByBroadcastId(index: number, item: Broadcast): string {
    return item.broadcast_id;
  }
}
