// messaging/broadcast/broadcast-management.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { MessagingService, Broadcast } from '../messaging.service';
import { TopComponent } from '../../partials/top/top.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';

// Interface for form target criteria (non-nullable arrays)
interface FormTargetCriteria {
  roles: number[];
  departments: number[];
  course_id: number | null;
}

@Component({
  selector: 'app-broadcast-management',
  standalone: true,
  imports: [CommonModule, FormsModule, TopComponent, TranslatePipe],
  template: `
    <app-top></app-top>

    <div class="broadcast-management">
      <!-- Header -->
      <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title mb-1">
              <i class="bi bi-megaphone me-2"></i>{{ 'messaging.broadcast_messages' | translate }}
            </h1>
            <p class="text-muted mb-0">{{ 'messaging.broadcast_subtitle' | translate }}</p>
          </div>
          <button class="btn btn-primary" (click)="openNewBroadcastModal()">
            <i class="bi bi-plus-lg me-2"></i>{{ 'messaging.new_broadcast' | translate }}
          </button>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="row g-4 mb-4">
        <div class="col-md-3">
          <div class="stat-card">
            <div class="stat-icon bg-primary-subtle">
              <i class="bi bi-send text-primary"></i>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.total_sent }}</div>
              <div class="stat-label">{{ 'messaging.total_sent' | translate }}</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <div class="stat-icon bg-info-subtle">
              <i class="bi bi-people text-info"></i>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.total_recipients }}</div>
              <div class="stat-label">{{ 'messaging.total_recipients' | translate }}</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <div class="stat-icon bg-success-subtle">
              <i class="bi bi-eye text-success"></i>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.avg_read_rate }}%</div>
              <div class="stat-label">{{ 'messaging.avg_read_rate' | translate }}</div>
            </div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stat-card">
            <div class="stat-icon bg-warning-subtle">
              <i class="bi bi-clock text-warning"></i>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.scheduled }}</div>
              <div class="stat-label">{{ 'messaging.scheduled' | translate }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabs -->
      <ul class="nav nav-pills mb-4">
        <li class="nav-item">
          <button class="nav-link" [class.active]="activeTab === 'sent'" (click)="activeTab = 'sent'">
            <i class="bi bi-check2-circle me-2"></i>{{ 'messaging.tab_sent' | translate }}
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" [class.active]="activeTab === 'scheduled'" (click)="activeTab = 'scheduled'">
            <i class="bi bi-clock me-2"></i>{{ 'messaging.scheduled' | translate }}
            <span class="badge bg-warning text-dark ms-2" *ngIf="scheduledBroadcasts.length">
              {{ scheduledBroadcasts.length }}
            </span>
          </button>
        </li>
        <li class="nav-item">
          <button class="nav-link" [class.active]="activeTab === 'drafts'" (click)="activeTab = 'drafts'">
            <i class="bi bi-file-earmark me-2"></i>{{ 'messaging.tab_drafts' | translate }}
          </button>
        </li>
      </ul>

      <!-- Broadcasts List -->
      <div class="card">
        <div class="card-body p-0">
          <div class="broadcast-list" *ngIf="getFilteredBroadcasts().length > 0; else emptyState">
            <div class="broadcast-item" *ngFor="let broadcast of getFilteredBroadcasts(); trackBy: trackByBroadcastId">
              <div class="broadcast-priority" [ngClass]="getPriorityClass(broadcast.priority)">
                <i class="bi" [ngClass]="getPriorityIcon(broadcast.priority)"></i>
              </div>
              <div class="broadcast-content flex-grow-1">
                <div class="d-flex justify-content-between align-items-start mb-2">
                  <h6 class="broadcast-title mb-0">{{ broadcast.title }}</h6>
                  <span class="badge" [ngClass]="getStatusBadge(broadcast.status)">
                    {{ broadcast.status | titlecase }}
                  </span>
                </div>
                <p class="broadcast-excerpt text-muted mb-2">{{ getExcerpt(broadcast.content) }}</p>
                <div class="broadcast-meta">
                  <span class="badge bg-light text-dark me-2">
                    <i class="bi bi-bullseye me-1"></i>
                    {{ getTargetLabel(broadcast) }}
                  </span>
                  <span class="text-muted small" *ngIf="broadcast.sent_at">
                    <i class="bi bi-clock me-1"></i>
                    {{ 'messaging.sent_prefix' | translate }} {{ formatDateTime(broadcast.sent_at) }}
                  </span>
                  <span class="text-muted small" *ngIf="broadcast.scheduled_at && broadcast.status === 'scheduled'">
                    <i class="bi bi-calendar me-1"></i>
                    {{ 'messaging.scheduled_for' | translate }} {{ formatDateTime(broadcast.scheduled_at) }}
                  </span>
                </div>
              </div>
              <div class="broadcast-stats text-center" *ngIf="broadcast.status === 'sent'">
                <div class="stat-mini">
                  <div class="stat-value">{{ broadcast.read_count }}/{{ broadcast.recipients_count }}</div>
                  <div class="stat-label">{{ 'messaging.read_stat' | translate }}</div>
                </div>
                <div class="progress mt-2" style="height: 4px; width: 80px;">
                  <div class="progress-bar bg-success" [style.width.%]="getReadPercentage(broadcast)"></div>
                </div>
              </div>
              <div class="broadcast-actions">
                <div class="btn-group btn-group-sm">
                  <button class="btn btn-primary" (click)="viewBroadcast(broadcast)" [title]="'messaging.view_details' | translate">
                    <i class="bi bi-eye"></i>
                  </button>
                  <button class="btn btn-secondary" *ngIf="broadcast.status === 'draft'"
                          (click)="editBroadcast(broadcast)" [title]="'common.edit' | translate">
                    <i class="bi bi-pencil"></i>
                  </button>
                  <button class="btn btn-success" *ngIf="broadcast.status === 'draft'"
                          (click)="sendBroadcast(broadcast)" [title]="'messaging.send_now' | translate">
                    <i class="bi bi-send"></i>
                  </button>
                  <button class="btn btn-outline-danger" *ngIf="broadcast.status !== 'sent'"
                          (click)="deleteBroadcast(broadcast)" [title]="'common.delete' | translate">
                    <i class="bi bi-trash"></i>
                  </button>
                </div>
              </div>
            </div>
          </div>
          <ng-template #emptyState>
            <div class="empty-state">
              <i class="bi bi-megaphone display-4 text-muted"></i>
              <h5 class="mt-3">{{ 'messaging.no_broadcasts' | translate }}</h5>
              <p class="text-muted">
                {{ (activeTab === 'sent' ? 'messaging.no_sent' :
                activeTab === 'scheduled' ? 'messaging.no_scheduled' :
                  'messaging.no_drafts') | translate }}
              </p>
              <button class="btn btn-primary" (click)="openNewBroadcastModal()">
                <i class="bi bi-plus-lg me-2"></i>{{ 'messaging.create_broadcast' | translate }}
              </button>
            </div>
          </ng-template>
        </div>
      </div>

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
                      <select class="form-select" [(ngModel)]="broadcastForm.priority" name="priority">
                        <option value="normal">{{ 'messaging.priority_normal' | translate }}</option>
                        <option value="important">{{ 'messaging.priority_important' | translate }}</option>
                        <option value="urgent">{{ 'messaging.priority_urgent' | translate }}</option>
                      </select>
                    </div>
                  </div>

                  <!-- Target Audience -->
                  <div class="col-md-6">
                    <div class="mb-3">
                      <label class="form-label">{{ 'messaging.target_audience' | translate }} *</label>
                      <select class="form-select" [(ngModel)]="broadcastForm.target_type" name="target_type"
                              (change)="onTargetTypeChange()">
                        <option value="all">{{ 'messaging.target_all' | translate }}</option>
                        <option value="role">{{ 'messaging.target_role' | translate }}</option>
                        <option value="department">{{ 'messaging.target_department' | translate }}</option>
                        <option value="course">{{ 'messaging.target_course_enrollment' | translate }}</option>
                      </select>
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
                  <select class="form-select" multiple [ngModel]="getSelectedDepartments()"
                          (ngModelChange)="setSelectedDepartments($event)" name="departments">
                    <option *ngFor="let dept of availableDepartments" [value]="dept.id">{{ dept.name }}</option>
                  </select>
                </div>

                <div class="mb-3" *ngIf="broadcastForm.target_type === 'course'">
                  <label class="form-label">{{ 'messaging.select_course' | translate }}</label>
                  <select class="form-select" [ngModel]="getSelectedCourseId()"
                          (ngModelChange)="setSelectedCourseId($event)" name="course_id">
                    <option value="">{{ 'messaging.select_course_ph' | translate }}</option>
                    <option *ngFor="let course of availableCourses" [value]="course.id">{{ course.title }}</option>
                  </select>
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
              <button class="btn btn-secondary" (click)="closeBroadcastModal()">{{ 'common.cancel' | translate }}</button>
              <button class="btn btn-primary" (click)="saveDraft()" [disabled]="!isFormValid()">
                <i class="bi bi-file-earmark me-2"></i>{{ 'messaging.save_draft' | translate }}
              </button>
              <button class="btn btn-primary" (click)="submitBroadcast()" [disabled]="!isFormValid()">
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
              <button class="btn btn-secondary" (click)="closeViewModal()">{{ 'common.close' | translate }}</button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-backdrop fade show" *ngIf="showViewModal" (click)="closeViewModal()"></div>
    </div>
  `,
  styles: [`
    .page-title {
      font-size: 1.75rem;
      font-weight: 600;
      color: #1a1a2e;

      i { color: #303234; }
    }

    .stat-card {
      background: #fff;
      border-radius: 12px;
      padding: 1.25rem;
      display: flex;
      align-items: center;
      gap: 1rem;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }

    .stat-icon {
      width: 56px;
      height: 56px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;

      i { font-size: 1.5rem; }
    }

    .stat-value {
      font-size: 1.75rem;
      font-weight: 700;
      color: #1a1a2e;
      line-height: 1;
    }

    .stat-label {
      font-size: 0.875rem;
      color: #6c757d;
    }

    .nav-pills {
      gap: 0.5rem;

      .nav-link {
        color: #6c757d;
        border-radius: 8px;

        &.active {
          background: #303234;
          color: #fff;
        }
      }
    }

    .broadcast-list {
      .broadcast-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1.25rem;
        border-bottom: 1px solid #f0f0f0;

        &:last-child { border-bottom: none; }

        &:hover { background: #f8f9fa; }
      }
    }

    .broadcast-priority {
      width: 40px;
      height: 40px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;

      &.priority-normal {
        background: rgba(108, 117, 125, 0.15);
        color: #6c757d;
      }

      &.priority-important {
        background: rgba(255, 193, 7, 0.15);
        color: #ffc107;
      }

      &.priority-urgent {
        background: rgba(220, 53, 69, 0.15);
        color: #dc3545;
      }
    }

    .broadcast-title {
      font-weight: 600;
      color: #1a1a2e;
    }

    .broadcast-excerpt {
      font-size: 0.875rem;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .stat-mini {
      .stat-value {
        font-size: 1rem;
        font-weight: 600;
      }
      .stat-label {
        font-size: 0.75rem;
      }
    }

    .empty-state {
      text-align: center;
      padding: 3rem;
    }

    .detail-stat {
      text-align: center;
      padding: 1rem;
      background: #f8f9fa;
      border-radius: 8px;

      .detail-stat-label {
        font-size: 0.8125rem;
        color: #6c757d;
        margin-bottom: 0.25rem;
      }

      .detail-stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1a1a2e;
      }
    }

    .bg-primary-subtle { background: rgba(201, 162, 39, 0.15) !important; }
    .bg-success-subtle { background: rgba(25, 135, 84, 0.15) !important; }
    .bg-warning-subtle { background: rgba(255, 193, 7, 0.15) !important; }
    .bg-info-subtle { background: rgba(13, 202, 240, 0.15) !important; }
  `]
})
export class BroadcastManagementComponent implements OnInit, OnDestroy {
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

  constructor(private messagingService: MessagingService, private i18n: I18nService, private dialog: ConfirmDialogService) {}

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
    // TODO: Implement delete
    this.loadBroadcasts();
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
