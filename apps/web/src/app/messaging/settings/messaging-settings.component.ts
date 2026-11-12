// messaging/settings/messaging-settings.component.ts

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule, Location } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { MessagingService, MessagingSettings, BlockedUser } from '../messaging.service';
import { TopComponent } from '../../partials/top/top.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { HotToastService } from '@ngneat/hot-toast';
import { AvatarComponent } from '../../shared/avatar/avatar.component';

@Component({
  selector: 'app-messaging-settings',
  standalone: true,
  imports: [CommonModule, FormsModule, TopComponent, TranslatePipe, AvatarComponent],
  template: `
    <app-top></app-top>

    <main class="main">
    <div class="ui-page messaging-settings">
      <!-- Header -->
      <header class="ui-page-header">
        <button type="button" class="ui-page-header__back" (click)="goBack()" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></button>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-gear"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ 'messaging.settings_title' | translate }}</h1>
          <p>{{ 'messaging.settings_subtitle' | translate }}</p>
        </div>
        <!-- Quick actions -->
        <div class="ui-page-header__actions" [attr.aria-label]="'messaging.quick_actions' | translate">
          <button type="button" class="btn btn-outline-secondary" (click)="markAllAsRead()">
            <i class="bi bi-check2-all me-1"></i>{{ 'messaging.mark_all_read' | translate }}
          </button>
          <button type="button" class="btn btn-outline-secondary" (click)="clearAllDrafts()">
            <i class="bi bi-file-earmark-x me-1"></i>{{ 'messaging.clear_all_drafts' | translate }}
          </button>
          <button type="button" class="btn btn-primary" (click)="exportData()">
            <i class="bi bi-download me-1"></i>{{ 'messaging.export_messages' | translate }}
          </button>
        </div>
      </header>

      <div class="ui-insights">
        <span class="ui-pill ui-pill--info"><i class="bi bi-info-circle"></i> {{ 'messaging.autosave_note' | translate }}</span>
      </div>

      <div class="ui-row">
        <!-- Privacy Settings -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title"><i class="bi bi-shield-lock me-2"></i>{{ 'messaging.privacy' | translate }}</h2></div>
          </div>
          <div class="setting-item">
            <div class="setting-info">
              <h3 class="setting-title">{{ 'messaging.allow_dm' | translate }}</h3>
              <p class="setting-desc">{{ 'messaging.allow_dm_desc' | translate }}</p>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="allowDM"
                     [(ngModel)]="settings.allow_direct_messages" (change)="saveSettings()">
            </div>
          </div>

          <div class="setting-item">
            <div class="setting-info">
              <h3 class="setting-title">{{ 'messaging.allow_group_invites' | translate }}</h3>
              <p class="setting-desc">{{ 'messaging.allow_group_invites_desc' | translate }}</p>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="allowGroups"
                     [(ngModel)]="settings.allow_group_invites" (change)="saveSettings()">
            </div>
          </div>

          <div class="setting-item">
            <div class="setting-info">
              <h3 class="setting-title">{{ 'messaging.show_online' | translate }}</h3>
              <p class="setting-desc">{{ 'messaging.show_online_desc' | translate }}</p>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="showOnline"
                     [(ngModel)]="settings.show_online_status" (change)="saveSettings()">
            </div>
          </div>

          <div class="setting-item">
            <div class="setting-info">
              <h3 class="setting-title">{{ 'messaging.show_receipts' | translate }}</h3>
              <p class="setting-desc">{{ 'messaging.show_receipts_desc' | translate }}</p>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="showRead"
                     [(ngModel)]="settings.show_read_receipts" (change)="saveSettings()">
            </div>
          </div>
        </section>

        <!-- Notification Settings -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title"><i class="bi bi-bell me-2"></i>{{ 'messaging.notifications' | translate }}</h2></div>
          </div>
          <div class="setting-item">
            <div class="setting-info">
              <h3 class="setting-title">{{ 'messaging.email_digest' | translate }}</h3>
              <p class="setting-desc">{{ 'messaging.email_digest_desc' | translate }}</p>
            </div>
            <select class="form-select" style="width: 150px;"
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
              <h3 class="setting-title">{{ 'messaging.quiet_hours' | translate }}</h3>
              <p class="setting-desc">{{ 'messaging.quiet_hours_desc' | translate }}</p>
            </div>
            <div class="d-flex align-items-center gap-2">
              <input type="time" class="form-control" style="width: 120px;"
                     [(ngModel)]="settings.quiet_hours_start" (change)="saveSettings()">
              <span class="ui-muted small">{{ 'messaging.time_to' | translate }}</span>
              <input type="time" class="form-control" style="width: 120px;"
                     [(ngModel)]="settings.quiet_hours_end" (change)="saveSettings()">
            </div>
          </div>
        </section>
      </div>

      <div class="ui-row">
        <!-- Auto-Reply Settings -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title"><i class="bi bi-reply-all me-2"></i>{{ 'messaging.auto_reply' | translate }}</h2></div>
          </div>
          <div class="setting-item">
            <div class="setting-info">
              <h3 class="setting-title">{{ 'messaging.enable_auto_reply' | translate }}</h3>
              <p class="setting-desc">{{ 'messaging.enable_auto_reply_desc' | translate }}</p>
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="autoReply"
                     [(ngModel)]="settings.auto_reply_enabled" (change)="saveSettings()">
            </div>
          </div>

          <div class="mt-3" *ngIf="settings.auto_reply_enabled">
            <label class="form-label small">{{ 'messaging.auto_reply_message' | translate }}</label>
            <textarea class="form-control" rows="3"
                      [(ngModel)]="settings.auto_reply_message"
                      placeholder="{{ 'messaging.auto_reply_ph' | translate }}"
                      (blur)="saveSettings()"></textarea>
          </div>
        </section>

        <!-- Blocked Users -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title"><i class="bi bi-person-x me-2"></i>{{ 'messaging.blocked_users' | translate }}</h2></div>
            <div class="ui-section__actions"><span class="ui-pill ui-pill--neutral">{{ blockedUsers.length }}</span></div>
          </div>
          <div *ngIf="blockedUsers.length === 0" class="ui-state">
            <span class="ui-state__icon"><i class="bi bi-person-check"></i></span>
            <p>{{ 'messaging.no_blocked' | translate }}</p>
          </div>

          <ul class="ui-list blocked-list" *ngIf="blockedUsers.length > 0">
            <li class="blocked-item" *ngFor="let user of blockedUsers">
              <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                <span class="ui-avatar avatar">
                  <app-avatar [src]="user.profile_image" [name]="(user.first_name || '') + ' ' + (user.last_name || '')"></app-avatar>
                </span>
                <div class="min-w-0">
                  <strong>{{ user.first_name }} {{ user.last_name }}</strong>
                  <div class="small ui-muted">{{ user.email }}</div>
                </div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-danger" (click)="unblockUser(user)">
                <i class="bi bi-person-plus me-1"></i>{{ 'messaging.unblock' | translate }}
              </button>
            </li>
          </ul>
        </section>
      </div>

      <!-- Save Indicator -->
      <div class="save-indicator" *ngIf="saving">
        <i class="bi bi-arrow-repeat spin me-2"></i>{{ 'messaging.saving' | translate }}
      </div>
      <div class="save-indicator saved" *ngIf="saved">
        <i class="bi bi-check-circle me-2"></i>{{ 'messaging.saved_indicator' | translate }}
      </div>
    </div>
    </main>
  `,
  styles: [`
    .ui-insights { margin-bottom: 16px; }

    .setting-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 14px 0;
      border-bottom: 1px solid var(--ui-border);
    }

    .setting-item:last-child { border-bottom: none; padding-bottom: 0; }

    .setting-title {
      font-size: .875rem;
      font-weight: 600;
      color: var(--ui-text);
      margin: 0 0 2px;
    }

    .setting-desc {
      font-size: 0.8125rem;
      color: var(--ui-text-muted);
      margin-bottom: 0;
    }

    .form-switch .form-check-input {
      width: 2.5em;
      height: 1.35em;
      cursor: pointer;
    }

    .form-switch .form-check-input:checked {
      background-color: var(--brand-primary);
      border-color: var(--brand-primary);
    }

    .avatar {
      width: 40px;
      height: 40px;
      flex-shrink: 0;
    }

    .blocked-item { justify-content: space-between; }

    .save-indicator {
      position: fixed;
      bottom: 20px;
      right: 20px;
      padding: 0.625rem 1rem;
      background: var(--ui-text, #1a1a2e);
      color: #fff;
      border-radius: var(--ui-radius-ctl, 8px);
      font-size: 0.8125rem;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
      z-index: 1000;
    }

    .save-indicator.saved {
      background: var(--ui-success, #2e7d4f);
    }

    .spin {
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    @media (max-width: 575.98px) {
      .setting-item { flex-wrap: wrap; }
    }
  `]
})
export class MessagingSettingsComponent implements OnInit, OnDestroy {
  private readonly backLocation = inject(Location);
  private readonly navRouter = inject(Router);
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

  /** Header back button: the previous page, or /messaging when there is no history (fresh tab / deep link). */
  goBack(): void {
    if (window.history.length > 1) {
      this.backLocation.back();
      return;
    }
    void this.navRouter.navigate(['/messaging']);
  }

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
