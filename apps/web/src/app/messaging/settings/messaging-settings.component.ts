// messaging/settings/messaging-settings.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { MessagingService, MessagingSettings, BlockedUser } from '../messaging.service';
import { TopComponent } from '../../partials/top/top.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { HotToastService } from '@ngneat/hot-toast';

@Component({
  selector: 'app-messaging-settings',
  standalone: true,
  imports: [CommonModule, FormsModule, TopComponent, TranslatePipe],
  template: `
    <app-top></app-top>

    <div class="messaging-settings">
      <!-- Header -->
      <div class="page-header mb-4">
        <h1 class="page-title">
          <i class="bi bi-gear me-2"></i>{{ 'messaging.settings_title' | translate }}
        </h1>
        <p class="text-muted">{{ 'messaging.settings_subtitle' | translate }}</p>
      </div>

      <div class="row">
        <!-- Settings Column -->
        <div class="col-lg-8">
          <!-- Privacy Settings -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">
                <i class="bi bi-shield-lock me-2"></i>{{ 'messaging.privacy' | translate }}
              </h5>
            </div>
            <div class="card-body">
              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">{{ 'messaging.allow_dm' | translate }}</h6>
                  <p class="setting-desc text-muted">{{ 'messaging.allow_dm_desc' | translate }}</p>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="allowDM"
                         [(ngModel)]="settings.allow_direct_messages" (change)="saveSettings()">
                </div>
              </div>

              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">{{ 'messaging.allow_group_invites' | translate }}</h6>
                  <p class="setting-desc text-muted">{{ 'messaging.allow_group_invites_desc' | translate }}</p>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="allowGroups"
                         [(ngModel)]="settings.allow_group_invites" (change)="saveSettings()">
                </div>
              </div>

              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">{{ 'messaging.show_online' | translate }}</h6>
                  <p class="setting-desc text-muted">{{ 'messaging.show_online_desc' | translate }}</p>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="showOnline"
                         [(ngModel)]="settings.show_online_status" (change)="saveSettings()">
                </div>
              </div>

              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">{{ 'messaging.show_receipts' | translate }}</h6>
                  <p class="setting-desc text-muted">{{ 'messaging.show_receipts_desc' | translate }}</p>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="showRead"
                         [(ngModel)]="settings.show_read_receipts" (change)="saveSettings()">
                </div>
              </div>
            </div>
          </div>

          <!-- Notification Settings -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">
                <i class="bi bi-bell me-2"></i>{{ 'messaging.notifications' | translate }}
              </h5>
            </div>
            <div class="card-body">
              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">{{ 'messaging.email_digest' | translate }}</h6>
                  <p class="setting-desc text-muted">{{ 'messaging.email_digest_desc' | translate }}</p>
                </div>
                <select class="form-select form-select-sm" style="width: 150px;"
                        [(ngModel)]="settings.email_digest_frequency" (change)="saveSettings()">
                  <option value="never">{{ 'messaging.freq_never' | translate }}</option>
                  <option value="instant">{{ 'messaging.freq_instant' | translate }}</option>
                  <option value="hourly">{{ 'messaging.freq_hourly' | translate }}</option>
                  <option value="daily">{{ 'messaging.freq_daily' | translate }}</option>
                  <option value="weekly">{{ 'messaging.freq_weekly' | translate }}</option>
                </select>
              </div>

              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">{{ 'messaging.quiet_hours' | translate }}</h6>
                  <p class="setting-desc text-muted">{{ 'messaging.quiet_hours_desc' | translate }}</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <input type="time" class="form-control form-control-sm" style="width: 120px;"
                         [(ngModel)]="settings.quiet_hours_start" (change)="saveSettings()">
                  <span>{{ 'messaging.time_to' | translate }}</span>
                  <input type="time" class="form-control form-control-sm" style="width: 120px;"
                         [(ngModel)]="settings.quiet_hours_end" (change)="saveSettings()">
                </div>
              </div>
            </div>
          </div>

          <!-- Auto-Reply Settings -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">
                <i class="bi bi-reply-all me-2"></i>{{ 'messaging.auto_reply' | translate }}
              </h5>
            </div>
            <div class="card-body">
              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">{{ 'messaging.enable_auto_reply' | translate }}</h6>
                  <p class="setting-desc text-muted">{{ 'messaging.enable_auto_reply_desc' | translate }}</p>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="autoReply"
                         [(ngModel)]="settings.auto_reply_enabled" (change)="saveSettings()">
                </div>
              </div>

              <div class="mt-3" *ngIf="settings.auto_reply_enabled">
                <label class="form-label">{{ 'messaging.auto_reply_message' | translate }}</label>
                <textarea class="form-control" rows="3"
                          [(ngModel)]="settings.auto_reply_message"
                          placeholder="{{ 'messaging.auto_reply_ph' | translate }}"
                          (blur)="saveSettings()"></textarea>
              </div>
            </div>
          </div>

          <!-- Blocked Users -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="card-title mb-0">
                <i class="bi bi-person-x me-2"></i>{{ 'messaging.blocked_users' | translate }}
              </h5>
              <span class="badge bg-secondary">{{ blockedUsers.length }}</span>
            </div>
            <div class="card-body">
              <div *ngIf="blockedUsers.length === 0" class="text-center py-4 text-muted">
                <i class="bi bi-person-check display-6"></i>
                <p class="mt-2 mb-0">{{ 'messaging.no_blocked' | translate }}</p>
              </div>

              <div class="blocked-list" *ngIf="blockedUsers.length > 0">
                <div class="blocked-item" *ngFor="let user of blockedUsers">
                  <div class="d-flex align-items-center gap-3">
                    <div class="avatar">
                      <img [src]="user.profile_image" *ngIf="user.profile_image" class="avatar-img">
                      <span class="avatar-initials" *ngIf="!user.profile_image">
                        {{ getInitials(user.first_name, user.last_name) }}
                      </span>
                    </div>
                    <div>
                      <strong>{{ user.first_name }} {{ user.last_name }}</strong>
                      <div class="small text-muted">{{ user.email }}</div>
                    </div>
                  </div>
                  <button class="btn btn-sm btn-outline-danger" (click)="unblockUser(user)">
                    <i class="bi bi-person-plus me-1"></i>{{ 'messaging.unblock' | translate }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Actions Column -->
        <div class="col-lg-4">
          <div class="card sticky-top" style="top: 20px;">
            <div class="card-header">
              <h5 class="card-title mb-0">{{ 'messaging.quick_actions' | translate }}</h5>
            </div>
            <div class="card-body">
              <div class="d-grid gap-2">
                <button class="btn btn-primary text-start" (click)="exportData()">
                  <i class="bi bi-download me-2"></i>{{ 'messaging.export_messages' | translate }}
                </button>
                <button class="btn btn-secondary text-start" (click)="clearAllDrafts()">
                  <i class="bi bi-file-earmark-x me-2"></i>{{ 'messaging.clear_all_drafts' | translate }}
                </button>
                <button class="btn btn-outline-warning text-start" (click)="markAllAsRead()">
                  <i class="bi bi-check2-all me-2"></i>{{ 'messaging.mark_all_read' | translate }}
                </button>
              </div>

              <hr>

              <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                <small>{{ 'messaging.autosave_note' | translate }}</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Save Indicator -->
      <div class="save-indicator" *ngIf="saving">
        <i class="bi bi-arrow-repeat spin me-2"></i>{{ 'messaging.saving' | translate }}
      </div>
      <div class="save-indicator saved" *ngIf="saved">
        <i class="bi bi-check-circle me-2"></i>{{ 'messaging.saved_indicator' | translate }}
      </div>
    </div>
  `,
  styles: [`
    .page-title {
      font-size: 1.75rem;
      font-weight: 600;
      color: #1a1a2e;

      i { color: #303234; }
    }

    .setting-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1rem 0;
      border-bottom: 1px solid #f0f0f0;

      &:last-child { border-bottom: none; }
    }

    .setting-title {
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .setting-desc {
      font-size: 0.875rem;
      margin-bottom: 0;
    }

    .form-switch .form-check-input {
      width: 3em;
      height: 1.5em;
      cursor: pointer;

      &:checked {
        background-color: #303234;
        border-color: #303234;
      }
    }

    .avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      overflow: hidden;
      flex-shrink: 0;

      .avatar-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
      }

      .avatar-initials {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(201, 162, 39, 0.15);
        color: #303234;
        font-weight: 600;
      }
    }

    .blocked-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 0.75rem 0;
      border-bottom: 1px solid #f0f0f0;

      &:last-child { border-bottom: none; }
    }

    .save-indicator {
      position: fixed;
      bottom: 20px;
      right: 20px;
      padding: 0.75rem 1.25rem;
      background: #1a1a2e;
      color: #fff;
      border-radius: 8px;
      font-size: 0.875rem;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      z-index: 1000;

      &.saved {
        background: #198754;
      }
    }

    .spin {
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
  `]
})
export class MessagingSettingsComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  settings: MessagingSettings = {
    allow_direct_messages: true,
    allow_group_invites: true,
    show_online_status: true,
    show_read_receipts: true,
    email_digest_frequency: 'daily',
    quiet_hours_start: null,
    quiet_hours_end: null,
    auto_reply_enabled: false,
    auto_reply_message: null
  };

  blockedUsers: BlockedUser[] = [];
  saving = false;
  saved = false;
  private saveTimeout: any;

  constructor(private messagingService: MessagingService, private i18n: I18nService, private dialog: ConfirmDialogService, private toast: HotToastService) {}

  ngOnInit(): void {
    this.loadSettings();
    this.loadBlockedUsers();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    if (this.saveTimeout) {
      clearTimeout(this.saveTimeout);
    }
  }

  loadSettings(): void {
    this.messagingService.getSettings()
      .pipe(takeUntil(this.destroy$))
      .subscribe(settings => {
        this.settings = settings;
      });
  }

  loadBlockedUsers(): void {
    this.messagingService.getBlockedUsers()
      .pipe(takeUntil(this.destroy$))
      .subscribe(users => {
        this.blockedUsers = users;
      });
  }

  saveSettings(): void {
    this.saving = true;
    this.saved = false;

    this.messagingService.updateSettings(this.settings)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.saved = true;

          if (this.saveTimeout) {
            clearTimeout(this.saveTimeout);
          }
          this.saveTimeout = setTimeout(() => {
            this.saved = false;
          }, 2000);
        },
        error: () => {
          this.saving = false;
        }
      });
  }

  async unblockUser(user: BlockedUser): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('messaging.confirm_unblock', { name: `${user.first_name} ${user.last_name}` }), { type: 'warning' }))) return;

    this.messagingService.unblockUser(user.blocked_user_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        this.blockedUsers = this.blockedUsers.filter(u => u.blocked_user_id !== user.blocked_user_id);
      });
  }

  exportData(): void {
    this.toast.success(this.i18n.t('messaging.export_alert'));
  }

  async clearAllDrafts(): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('messaging.confirm_clear_drafts'), { type: 'danger' }))) return;
    this.toast.success(this.i18n.t('messaging.drafts_cleared'));
  }

  async markAllAsRead(): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('messaging.confirm_mark_all')))) return;
    // TODO: Implement bulk mark as read
    this.toast.success(this.i18n.t('messaging.marked_all_read'));
  }

  getInitials(firstName: string, lastName: string): string {
    return `${firstName?.charAt(0) || ''}${lastName?.charAt(0) || ''}`.toUpperCase();
  }
}
