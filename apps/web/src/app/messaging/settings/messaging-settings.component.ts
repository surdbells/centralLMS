// messaging/settings/messaging-settings.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { MessagingService, MessagingSettings, BlockedUser } from '../messaging.service';

@Component({
  selector: 'app-messaging-settings',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="messaging-settings">
      <!-- Header -->
      <div class="page-header mb-4">
        <h1 class="page-title">
          <i class="bi bi-gear me-2"></i>Messaging Settings
        </h1>
        <p class="text-muted">Manage your messaging preferences and privacy</p>
      </div>

      <div class="row">
        <!-- Settings Column -->
        <div class="col-lg-8">
          <!-- Privacy Settings -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="card-title mb-0">
                <i class="bi bi-shield-lock me-2"></i>Privacy
              </h5>
            </div>
            <div class="card-body">
              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">Allow Direct Messages</h6>
                  <p class="setting-desc text-muted">Allow other users to send you direct messages</p>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="allowDM"
                         [(ngModel)]="settings.allow_direct_messages" (change)="saveSettings()">
                </div>
              </div>

              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">Allow Group Invites</h6>
                  <p class="setting-desc text-muted">Allow others to add you to group conversations</p>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="allowGroups"
                         [(ngModel)]="settings.allow_group_invites" (change)="saveSettings()">
                </div>
              </div>

              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">Show Online Status</h6>
                  <p class="setting-desc text-muted">Let others see when you're online</p>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="showOnline"
                         [(ngModel)]="settings.show_online_status" (change)="saveSettings()">
                </div>
              </div>

              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">Show Read Receipts</h6>
                  <p class="setting-desc text-muted">Let others see when you've read their messages</p>
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
                <i class="bi bi-bell me-2"></i>Notifications
              </h5>
            </div>
            <div class="card-body">
              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">Email Digest</h6>
                  <p class="setting-desc text-muted">How often to receive email summaries of unread messages</p>
                </div>
                <select class="form-select form-select-sm" style="width: 150px;"
                        [(ngModel)]="settings.email_digest_frequency" (change)="saveSettings()">
                  <option value="never">Never</option>
                  <option value="instant">Instant</option>
                  <option value="hourly">Hourly</option>
                  <option value="daily">Daily</option>
                  <option value="weekly">Weekly</option>
                </select>
              </div>

              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">Quiet Hours</h6>
                  <p class="setting-desc text-muted">Don't send notifications during these hours</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <input type="time" class="form-control form-control-sm" style="width: 120px;"
                         [(ngModel)]="settings.quiet_hours_start" (change)="saveSettings()">
                  <span>to</span>
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
                <i class="bi bi-reply-all me-2"></i>Auto-Reply
              </h5>
            </div>
            <div class="card-body">
              <div class="setting-item">
                <div class="setting-info">
                  <h6 class="setting-title">Enable Auto-Reply</h6>
                  <p class="setting-desc text-muted">Automatically reply to new messages when you're away</p>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="autoReply"
                         [(ngModel)]="settings.auto_reply_enabled" (change)="saveSettings()">
                </div>
              </div>

              <div class="mt-3" *ngIf="settings.auto_reply_enabled">
                <label class="form-label">Auto-Reply Message</label>
                <textarea class="form-control" rows="3"
                          [(ngModel)]="settings.auto_reply_message"
                          placeholder="I'm currently unavailable and will respond as soon as possible."
                          (blur)="saveSettings()"></textarea>
              </div>
            </div>
          </div>

          <!-- Blocked Users -->
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="card-title mb-0">
                <i class="bi bi-person-x me-2"></i>Blocked Users
              </h5>
              <span class="badge bg-secondary">{{ blockedUsers.length }}</span>
            </div>
            <div class="card-body">
              <div *ngIf="blockedUsers.length === 0" class="text-center py-4 text-muted">
                <i class="bi bi-person-check display-6"></i>
                <p class="mt-2 mb-0">You haven't blocked anyone</p>
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
                    <i class="bi bi-person-plus me-1"></i>Unblock
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
              <h5 class="card-title mb-0">Quick Actions</h5>
            </div>
            <div class="card-body">
              <div class="d-grid gap-2">
                <button class="btn btn-primary text-start" (click)="exportData()">
                  <i class="bi bi-download me-2"></i>Export My Messages
                </button>
                <button class="btn btn-secondary text-start" (click)="clearAllDrafts()">
                  <i class="bi bi-file-earmark-x me-2"></i>Clear All Drafts
                </button>
                <button class="btn btn-outline-warning text-start" (click)="markAllAsRead()">
                  <i class="bi bi-check2-all me-2"></i>Mark All as Read
                </button>
              </div>

              <hr>

              <div class="alert alert-info mb-0">
                <i class="bi bi-info-circle me-2"></i>
                <small>Changes are saved automatically when you modify settings.</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Save Indicator -->
      <div class="save-indicator" *ngIf="saving">
        <i class="bi bi-arrow-repeat spin me-2"></i>Saving...
      </div>
      <div class="save-indicator saved" *ngIf="saved">
        <i class="bi bi-check-circle me-2"></i>Saved
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

  constructor(private messagingService: MessagingService) {}

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

  unblockUser(user: BlockedUser): void {
    if (!confirm(`Unblock ${user.first_name} ${user.last_name}?`)) return;

    this.messagingService.unblockUser(user.blocked_user_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        this.blockedUsers = this.blockedUsers.filter(u => u.blocked_user_id !== user.blocked_user_id);
      });
  }

  exportData(): void {
    alert('Your message data will be prepared and sent to your email.');
  }

  clearAllDrafts(): void {
    if (!confirm('Clear all saved drafts?')) return;
    alert('All drafts have been cleared.');
  }

  markAllAsRead(): void {
    if (!confirm('Mark all conversations as read?')) return;
    // TODO: Implement bulk mark as read
    alert('All conversations marked as read.');
  }

  getInitials(firstName: string, lastName: string): string {
    return `${firstName?.charAt(0) || ''}${lastName?.charAt(0) || ''}`.toUpperCase();
  }
}
