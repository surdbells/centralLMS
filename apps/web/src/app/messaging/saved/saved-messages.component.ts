// messaging/saved/saved-messages.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { MessagingService, SavedMessage } from '../messaging.service';
import { TopComponent } from '../../partials/top/top.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';

@Component({
  selector: 'app-saved-messages',
  standalone: true,
  imports: [CommonModule, FormsModule, TopComponent, TranslatePipe],
  template: `
    <app-top></app-top>

    <main class="main">
    <div class="ui-page saved-messages">
      <!-- Header -->
      <header class="ui-page-header">
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-bookmark-star"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ 'messaging.saved_messages' | translate }}</h1>
          <p>{{ 'messaging.saved_count' | translate:{ count: savedMessages.length } }}</p>
        </div>
      </header>

      <section class="ui-section">
        <div class="ui-toolbar">
          <label class="ui-search">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="{{ 'messaging.search_saved' | translate }}"
                   [(ngModel)]="searchTerm" (input)="filterMessages()">
          </label>
        </div>

        <!-- Message List -->
        <div *ngIf="filteredMessages.length > 0; else emptyState">
          <ul class="ui-list saved-list">
            <li class="saved-item" *ngFor="let saved of filteredMessages; trackBy: trackById">
              <div class="saved-content">
                <div class="saved-header">
                  <div class="sender-info">
                    <strong>{{ saved.sender_first_name }} {{ saved.sender_last_name }}</strong>
                    <span class="ui-muted mx-2">{{ 'messaging.in_word' | translate }}</span>
                    <span class="conversation-name">{{ saved.conversation_name || ('messaging.direct_message' | translate) }}</span>
                  </div>
                  <span class="saved-date ui-muted small">
                    {{ formatDate(saved.message_created_at) }}
                  </span>
                </div>
                <div class="message-content">{{ saved.content }}</div>
                <div class="saved-note" *ngIf="saved.note">
                  <i class="bi bi-sticky me-1"></i>
                  {{ saved.note }}
                </div>
              </div>
              <div class="saved-actions">
                <button type="button" class="btn btn-sm btn-primary" (click)="goToMessage(saved)" [title]="'messaging.go_to_message' | translate">
                  <i class="bi bi-arrow-right"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" (click)="editNote(saved)" [title]="'messaging.edit_note' | translate">
                  <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" (click)="unsave(saved)" [title]="'common.remove' | translate">
                  <i class="bi bi-bookmark-x"></i>
                </button>
              </div>
            </li>
          </ul>

          <!-- Load More -->
          <div class="text-center pt-3" *ngIf="hasMore">
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="loadMore()" [disabled]="loading">
              {{ (loading ? 'common.loading' : 'messaging.load_more') | translate }}
            </button>
          </div>
        </div>

        <ng-template #emptyState>
          <div class="ui-state empty-state">
            <span class="ui-state__icon"><i class="bi bi-bookmark"></i></span>
            <h3>{{ 'messaging.no_saved' | translate }}</h3>
            <p>
              {{ (searchTerm ? 'messaging.no_saved_search' : 'messaging.no_saved_hint') | translate }}
            </p>
          </div>
        </ng-template>
      </section>

      <!-- Edit Note Modal -->
      <div class="modal fade" [class.show]="showNoteModal" [style.display]="showNoteModal ? 'block' : 'none'">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">
                <i class="bi bi-sticky me-2"></i>{{ 'messaging.edit_note' | translate }}
              </h5>
              <button type="button" class="btn-close" (click)="closeNoteModal()"></button>
            </div>
            <div class="modal-body">
              <textarea class="form-control" rows="4" [(ngModel)]="editingNote"
                        placeholder="{{ 'messaging.note_ph' | translate }}"></textarea>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" (click)="closeNoteModal()">{{ 'common.cancel' | translate }}</button>
              <button type="button" class="btn btn-primary" (click)="saveNote()">{{ 'messaging.save_note' | translate }}</button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-backdrop fade show" *ngIf="showNoteModal" (click)="closeNoteModal()"></div>
    </div>
    </main>
  `,
  styles: [`
    .saved-list > li.saved-item {
      align-items: flex-start;
      justify-content: space-between;
      padding: 14px 0;
    }

    .saved-content {
      flex: 1;
      min-width: 0;
    }

    .saved-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 0.375rem;
      font-size: .875rem;
    }

    .conversation-name {
      color: var(--brand-primary);
      font-weight: 500;
    }

    .message-content {
      color: var(--ui-text);
      font-size: .875rem;
      margin-bottom: 0.375rem;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .saved-note {
      font-size: 0.8125rem;
      color: var(--ui-text-muted);
      font-style: italic;
      padding: 6px 10px;
      background: rgba(var(--ui-warning-rgb, 178, 94, 9), 0.08);
      border: 1px solid rgba(var(--ui-warning-rgb, 178, 94, 9), 0.2);
      border-radius: var(--ui-radius-ctl, 8px);
      margin-top: 0.375rem;
    }

    .saved-note .bi { color: var(--ui-warning-text, #8a4a07); }

    .saved-actions {
      display: flex;
      gap: 0.5rem;
      margin-left: 1rem;
      flex-shrink: 0;
    }

    @media (max-width: 575.98px) {
      .saved-list > li.saved-item { flex-wrap: wrap; }
      .saved-actions { margin-left: 0; }
    }
  `]
})
export class SavedMessagesComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  savedMessages: SavedMessage[] = [];
  filteredMessages: SavedMessage[] = [];
  searchTerm = '';
  loading = false;
  hasMore = false;
  offset = 0;
  limit = 20;

  // Note Modal
  showNoteModal = false;
  editingSaved: SavedMessage | null = null;
  editingNote = '';

  constructor(private messagingService: MessagingService, private i18n: I18nService, private dialog: ConfirmDialogService) {}

  ngOnInit(): void {
    this.loadSavedMessages();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadSavedMessages(): void {
    this.loading = true;
    this.messagingService.getSavedMessages(this.limit, this.offset)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (messages) => {
          this.savedMessages = [...this.savedMessages, ...messages];
          this.filterMessages();
          this.hasMore = messages.length === this.limit;
          this.loading = false;
        },
        error: () => {
          this.loading = false;
        }
      });
  }

  loadMore(): void {
    this.offset += this.limit;
    this.loadSavedMessages();
  }

  filterMessages(): void {
    if (!this.searchTerm) {
      this.filteredMessages = this.savedMessages;
      return;
    }

    const term = this.searchTerm.toLowerCase();
    this.filteredMessages = this.savedMessages.filter(m =>
      m.content.toLowerCase().includes(term) ||
      m.note?.toLowerCase().includes(term) ||
      m.sender_first_name.toLowerCase().includes(term) ||
      m.sender_last_name.toLowerCase().includes(term)
    );
  }

  goToMessage(saved: SavedMessage): void {
    // Navigate to conversation with message highlighted
    window.location.href = `/messaging/conversation/${saved.conversation_id}?message=${saved.message_id}`;
  }

  editNote(saved: SavedMessage): void {
    this.editingSaved = saved;
    this.editingNote = saved.note || '';
    this.showNoteModal = true;
  }

  closeNoteModal(): void {
    this.showNoteModal = false;
    this.editingSaved = null;
    this.editingNote = '';
  }

  saveNote(): void {
    if (!this.editingSaved) return;

    this.messagingService.saveMessageBookmark(this.editingSaved.message_id, this.editingNote)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        if (this.editingSaved) {
          this.editingSaved.note = this.editingNote;
        }
        this.closeNoteModal();
      });
  }

  async unsave(saved: SavedMessage): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('messaging.confirm_remove_saved'), { type: 'danger' }))) return;

    this.messagingService.unsaveMessage(saved.message_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        this.savedMessages = this.savedMessages.filter(m => m.message_id !== saved.message_id);
        this.filterMessages();
      });
  }

  formatDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  trackById(index: number, item: SavedMessage): string {
    return item.message_id;
  }
}
