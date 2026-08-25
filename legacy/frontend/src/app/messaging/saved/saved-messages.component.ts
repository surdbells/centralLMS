// messaging/saved/saved-messages.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { MessagingService, SavedMessage } from '../messaging.service';

@Component({
  selector: 'app-saved-messages',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="saved-messages">
      <!-- Header -->
      <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title mb-1">
              <i class="bi bi-bookmark-star me-2"></i>Saved Messages
            </h1>
            <p class="text-muted mb-0">{{ savedMessages.length }} saved messages</p>
          </div>
          <div class="d-flex gap-2">
            <input type="text" class="form-control" placeholder="Search saved messages..."
                   [(ngModel)]="searchTerm" (input)="filterMessages()">
          </div>
        </div>
      </div>

      <!-- Message List -->
      <div class="card" *ngIf="filteredMessages.length > 0; else emptyState">
        <div class="card-body p-0">
          <div class="saved-list">
            <div class="saved-item" *ngFor="let saved of filteredMessages; trackBy: trackById">
              <div class="saved-content">
                <div class="saved-header">
                  <div class="sender-info">
                    <strong>{{ saved.sender_first_name }} {{ saved.sender_last_name }}</strong>
                    <span class="text-muted mx-2">in</span>
                    <span class="conversation-name">{{ saved.conversation_name || 'Direct Message' }}</span>
                  </div>
                  <span class="saved-date text-muted small">
                    {{ formatDate(saved.message_created_at) }}
                  </span>
                </div>
                <div class="message-content">{{ saved.content }}</div>
                <div class="saved-note" *ngIf="saved.note">
                  <i class="bi bi-sticky text-warning me-1"></i>
                  {{ saved.note }}
                </div>
              </div>
              <div class="saved-actions">
                <button class="btn btn-sm btn-primary" (click)="goToMessage(saved)" title="Go to message">
                  <i class="bi bi-arrow-right"></i>
                </button>
                <button class="btn btn-sm btn-secondary" (click)="editNote(saved)" title="Edit note">
                  <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger" (click)="unsave(saved)" title="Remove">
                  <i class="bi bi-bookmark-x"></i>
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Load More -->
        <div class="card-footer text-center" *ngIf="hasMore">
          <button class="btn btn-link" (click)="loadMore()" [disabled]="loading">
            {{ loading ? 'Loading...' : 'Load More' }}
          </button>
        </div>
      </div>

      <ng-template #emptyState>
        <div class="empty-state card">
          <div class="card-body text-center py-5">
            <i class="bi bi-bookmark display-4 text-muted"></i>
            <h5 class="mt-3">No Saved Messages</h5>
            <p class="text-muted">
              {{ searchTerm ? 'No messages match your search.' : 'Save important messages to find them easily later.' }}
            </p>
          </div>
        </div>
      </ng-template>

      <!-- Edit Note Modal -->
      <div class="modal fade" [class.show]="showNoteModal" [style.display]="showNoteModal ? 'block' : 'none'">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">
                <i class="bi bi-sticky me-2"></i>Edit Note
              </h5>
              <button type="button" class="btn-close" (click)="closeNoteModal()"></button>
            </div>
            <div class="modal-body">
              <textarea class="form-control" rows="4" [(ngModel)]="editingNote"
                        placeholder="Add a personal note about this saved message..."></textarea>
            </div>
            <div class="modal-footer">
              <button class="btn btn-secondary" (click)="closeNoteModal()">Cancel</button>
              <button class="btn btn-primary" (click)="saveNote()">Save Note</button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-backdrop fade show" *ngIf="showNoteModal" (click)="closeNoteModal()"></div>
    </div>
  `,
  styles: [`
    .page-title {
      font-size: 1.75rem;
      font-weight: 600;
      color: #1a1a2e;

      i { color: #303234; }
    }

    .saved-list {
      .saved-item {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 1.25rem;
        border-bottom: 1px solid #f0f0f0;

        &:last-child { border-bottom: none; }

        &:hover { background: #f8f9fa; }
      }
    }

    .saved-content {
      flex: 1;
      min-width: 0;
    }

    .saved-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0.5rem;
    }

    .conversation-name {
      color: #303234;
      font-weight: 500;
    }

    .message-content {
      color: #1a1a2e;
      margin-bottom: 0.5rem;
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }

    .saved-note {
      font-size: 0.875rem;
      color: #6c757d;
      font-style: italic;
      padding: 0.5rem;
      background: rgba(255, 193, 7, 0.1);
      border-radius: 4px;
      margin-top: 0.5rem;
    }

    .saved-actions {
      display: flex;
      gap: 0.5rem;
      margin-left: 1rem;
    }

    .empty-state {
      .bi {
        color: #303234 !important;
      }
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

  constructor(private messagingService: MessagingService) {}

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

  unsave(saved: SavedMessage): void {
    if (!confirm('Remove this saved message?')) return;

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

  trackById(index: number, item: SavedMessage): number {
    return item.message_id;
  }
}
