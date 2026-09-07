// messaging/messaging.component.ts

import { Component, OnInit, OnDestroy, ViewChild, ElementRef, HostListener } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil, finalize } from 'rxjs/operators';
import {
  MessagingService,
  Conversation,
  Message,
  Participant,
  TypingUser,
  UnreadCounts,
  Broadcast,
  ReadReceipt
} from './messaging.service';
import {AdmintopComponent} from '../partials/top/admintop/admintop.component';
import {FileUploadService} from './file-upload.service';
import {TopComponent} from '../partials/top/top.component';
import {TranslatePipe} from '../translate.pipe';
import {I18nService} from '../i18n.service';

@Component({
  selector: 'app-messaging',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent, TopComponent, TranslatePipe],
  templateUrl: './messaging.component.html',
  styleUrls: ['./messaging.component.scss']
})
export class MessagingComponent implements OnInit, OnDestroy {
  @ViewChild('messagesContainer') messagesContainer!: ElementRef;
  @ViewChild('messageInput') messageInput!: ElementRef;
  @ViewChild('fileInput') fileInput!: ElementRef;

  private destroy$ = new Subject<void>();

  // Data
  conversations: Conversation[] = [];
  filteredConversations: Conversation[] = [];
  activeConversation: Conversation | null = null;
  messages: Message[] = [];
  participants: Participant[] = [];
  typingUsers: TypingUser[] = [];
  unreadCounts: UnreadCounts = { total_unread: 0, unread_mentions: 0, unread_broadcasts: 0 };
  broadcasts: Broadcast[] = [];

  // UI State
  loading = true;
  loadingMessages = false;
  sendingMessage = false;
  showConversationList = true;
  showParticipants = false;
  showNewConversation = false;
  showBroadcasts = false;
  showEmojiPicker = false;
  showForwardModal = false;
  isMobile = false;

  // Additional Modal UI States
  showConversationSettings = false;
  showAddParticipantsModal = false;
  showReadReceiptsModal = false;
  showMessageSearchModal = false;
  showBlockUserModal = false;
  showBroadcastDetailModal = false;
  showParticipantRoleModal = false;
  showMessageDetailModal = false;

  // Message Composition
  messageContent = '';
  replyingTo: Message | null = null;
  editingMessage: Message | null = null;
  selectedAttachments: File[] = [];
  attachmentPreviews: string[] = [];

  // Search & Filter
  conversationSearch = '';
  conversationFilter: 'all' | 'direct' | 'group' | 'channel' | 'archived' = 'all';

  // Message Search
  messageSearchQuery = '';
  messageSearchResults: Message[] = [];
  searchingMessages = false;
  searchFromDate = '';
  searchToDate = '';
  searchHasAttachments = false;

  // New Conversation
  newConversationType: 'direct' | 'group' | 'channel' = 'direct';
  newConversationName = '';
  newConversationDescription = '';
  userSearchQuery = '';
  userSearchResults: any[] = [];
  selectedUsers: any[] = [];

  // Forward
  forwardingMessage: Message | null = null;
  forwardTargets: string[] = [];

  // Emoji
  commonEmojis = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🎉', '🔥', '✅', '👀'];

  // Context Menu
  contextMenuMessage: Message | null = null;
  contextMenuPosition = { x: 0, y: 0 };
  showContextMenu = false;

  // Infinite Scroll
  hasMoreMessages = true;
  loadingMoreMessages = false;

  // Typing
  private typingTimeout: any;
  private isTyping = false;

  // Polling interval
  private pollingInterval: any;

  // Read Receipts Modal State
  readReceipts: ReadReceipt[] = [];
  loadingReadReceipts = false;
  selectedMessageForReceipts: Message | null = null;

  // Conversation Settings
  editingConversationName = '';
  editingConversationDescription = '';
  editingConversationIsPrivate = false;
  savingConversation = false;

  // Add Participants
  newParticipantSearchQuery = '';
  newParticipantSearchResults: any[] = [];
  selectedNewParticipants: any[] = [];
  addingParticipants = false;

  // Participant Role
  selectedParticipant: Participant | null = null;
  selectedParticipantRole = 'member';

  // Block User
  userToBlock: Participant | null = null;
  blockReason = '';
  blockingUser = false;

  // Message Detail
  selectedMessageDetail: Message | null = null;
  loadingMessageDetail = false;

  // Broadcast Detail
  selectedBroadcast: Broadcast | null = null;
  loadingBroadcast = false;

  constructor(
    public messagingService: MessagingService,
    private fileUploadService: FileUploadService,
    private i18n: I18nService
  ) {}

  ngOnInit(): void {
    this.checkMobile();
    this.loadConversations();
    this.loadUnreadCounts();
    this.loadBroadcasts();
    this.setupSubscriptions();
    this.startPolling();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    if (this.pollingInterval) {
      clearInterval(this.pollingInterval);
    }
    if (this.typingTimeout) {
      clearTimeout(this.typingTimeout);
    }
  }

  @HostListener('window:resize')
  onResize(): void {
    this.checkMobile();
  }

  private checkMobile(): void {
    this.isMobile = window.innerWidth < 768;
    if (!this.isMobile) {
      this.showConversationList = true;
    }
  }

  private setupSubscriptions(): void {
    this.messagingService.conversations$
      .pipe(takeUntil(this.destroy$))
      .subscribe(convs => {
        this.conversations = convs;
        this.applyConversationFilter();
      });

    // Subscribe to activeConversation$ observable
    this.messagingService.activeConversation$
      .pipe(takeUntil(this.destroy$))
      .subscribe(conversation => {
        if (conversation && this.activeConversation?.conversation_id !== conversation.conversation_id) {
          // Conversation changed externally
          this.activeConversation = conversation;
        }
      });

    this.messagingService.messages$
      .pipe(takeUntil(this.destroy$))
      .subscribe(msgs => {
        this.messages = msgs;
      });

    this.messagingService.unreadCounts$
      .pipe(takeUntil(this.destroy$))
      .subscribe(counts => {
        this.unreadCounts = counts;
      });

    this.messagingService.typingUsers$
      .pipe(takeUntil(this.destroy$))
      .subscribe(users => {
        this.typingUsers = users;
      });

    this.messagingService.newMessage$
      .pipe(takeUntil(this.destroy$))
      .subscribe(msg => {
        if (this.activeConversation && msg.conversation_id === this.activeConversation.conversation_id) {
          this.scrollToBottom();
        }
      });

    // Subscribe to reactionUpdated$ observable
    this.messagingService.reactionUpdated$
      .pipe(takeUntil(this.destroy$))
      .subscribe(({ message_id, reactions }) => {
        // Update reactions in local message list
        const message = this.messages.find(m => m.message_id === message_id);
        if (message) {
          message.reactions = reactions;
        }
      });
  }

  private startPolling(): void {
    // Poll for new messages every 5 seconds
    this.pollingInterval = setInterval(() => {
      if (this.activeConversation) {
        this.checkForNewMessages();
        this.messagingService.getTypingUsers(this.activeConversation.conversation_id)
          .pipe(takeUntil(this.destroy$))
          .subscribe();
      }
      this.loadUnreadCounts();
    }, 5000);
  }

  /**
   * Polls for anything said since the last poll.
   *
   * It used to ask for messages after the highest id it held, worked out
   * with `Math.max` over the ids — which only means anything while ids are
   * integers that increase. UUIDs do not, so the newest page is fetched and
   * compared against what is already on screen.
   */
  private checkForNewMessages(): void {
    if (!this.activeConversation || this.messages.length === 0) return;

    const known = new Set(this.messages.map((m) => m.message_id));

    this.messagingService.getMessages(this.activeConversation.conversation_id, { limit: 50 })
      .pipe(takeUntil(this.destroy$))
      .subscribe(newMessages => {
        if (newMessages.some((m) => !known.has(m.message_id))) {
          this.scrollToBottom();
        }
      });
  }

  // ==========================================
  // CONVERSATIONS
  // ==========================================

  loadConversations(): void {
    this.loading = true;
    this.messagingService.getConversations()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loading = false;
        },
        error: err => {
          console.error('Error loading conversations:', err);
          this.loading = false;
        }
      });
  }

  loadUnreadCounts(): void {
    this.messagingService.getUnreadCounts()
      .pipe(takeUntil(this.destroy$))
      .subscribe();
  }

  loadBroadcasts(): void {
    this.messagingService.getMyBroadcasts()
      .pipe(takeUntil(this.destroy$))
      .subscribe(broadcasts => {
        this.broadcasts = broadcasts;
      });
  }

  selectConversation(conversation: Conversation): void {
    this.activeConversation = conversation;
    this.messagingService.setActiveConversation(conversation);
    this.loadMessages();
    this.loadParticipants();
    this.markAsRead();

    // Load draft
    this.messagingService.getDraft(conversation.conversation_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe(draft => {
        if (draft) {
          this.messageContent = draft.content;
        }
      });

    if (this.isMobile) {
      this.showConversationList = false;
    }

    this.replyingTo = null;
    this.editingMessage = null;
  }

  loadMessages(): void {
    if (!this.activeConversation) return;

    this.loadingMessages = true;
    this.messagingService.getMessages(this.activeConversation.conversation_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadingMessages = false;
          this.scrollToBottom();
          this.hasMoreMessages = this.messages.length >= 50;
        },
        error: err => {
          console.error('Error loading messages:', err);
          this.loadingMessages = false;
        }
      });
  }

  loadMoreMessages(): void {
    if (!this.activeConversation || this.loadingMoreMessages || !this.hasMoreMessages) return;

    // The oldest message on screen, not the smallest id: ids are UUIDs
    // and do not sort by age. The list is oldest-first, so it is the
    // first one.
    const oldest = this.messages[0]?.message_id;
    this.loadingMoreMessages = true;

    this.messagingService.getMessages(this.activeConversation.conversation_id, { before: oldest })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: msgs => {
          this.loadingMoreMessages = false;
          this.hasMoreMessages = msgs.length >= 50;
        },
        error: () => {
          this.loadingMoreMessages = false;
        }
      });
  }

  loadParticipants(): void {
    if (!this.activeConversation) return;

    this.messagingService.getParticipants(this.activeConversation.conversation_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe(participants => {
        this.participants = participants;
      });
  }

  markAsRead(): void {
    if (!this.activeConversation) return;

    this.messagingService.markAsRead(this.activeConversation.conversation_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe();
  }

  applyConversationFilter(): void {
    let result = [...this.conversations];

    // Filter by type
    if (this.conversationFilter === 'archived') {
      // Already filtered on server, but just in case
      result = result.filter(c => c.is_archived);
    } else if (this.conversationFilter !== 'all') {
      result = result.filter(c => c.type === this.conversationFilter);
    }

    // Search
    if (this.conversationSearch) {
      const search = this.conversationSearch.toLowerCase();
      result = result.filter(c =>
        c.display_name?.toLowerCase().includes(search) ||
        c.name?.toLowerCase().includes(search)
      );
    }

    this.filteredConversations = result;
  }

  onConversationSearchChange(): void {
    this.applyConversationFilter();
  }

  onConversationFilterChange(): void {
    if (this.conversationFilter === 'archived') {
      this.messagingService.getConversations({ is_archived: 1 })
        .pipe(takeUntil(this.destroy$))
        .subscribe();
    } else {
      this.messagingService.getConversations({ is_archived: 0 })
        .pipe(takeUntil(this.destroy$))
        .subscribe();
    }
  }

  // ==========================================
  // MESSAGING
  // ==========================================

  sendMessage(): void {
    if (!this.activeConversation) return;
    if (!this.messageContent.trim() && this.selectedAttachments.length === 0) return;

    // Handle edit
    if (this.editingMessage) {
      this.saveEdit();
      return;
    }

    this.sendingMessage = true;

    const messageData: any = {
      content: this.messageContent.trim()
    };

    if (this.replyingTo) {
      messageData.reply_to_id = this.replyingTo.message_id;
    }

    // Handle attachments - upload files first
    if (this.selectedAttachments.length > 0) {
      this.fileUploadService.uploadMessageAttachments(this.selectedAttachments)
        .pipe(
          takeUntil(this.destroy$),
          finalize(() => this.sendingMessage = false)
        )
        .subscribe({
          next: (uploadedFiles) => {
            // Add uploaded file info to message
            messageData.attachments = uploadedFiles.map(file => ({
              file_name: file.file_name,
              file_path: file.file_path,
              file_size: file.file_size,
              file_type: file.file_type,
              thumbnail_path: file.thumbnail_path
            }));

            // Send message with attachments
            this.sendMessageWithData(messageData);
          },
          error: (err) => {
            console.error('Error uploading files:', err);
            alert(this.i18n.t('messaging.upload_failed'));
          }
        });
    } else {
      // No attachments, send directly
      this.sendMessageWithData(messageData);
    }
  }

  private sendMessageWithData(messageData: any): void {
    this.messagingService.sendMessage(this.activeConversation!.conversation_id, messageData)
      .pipe(
        takeUntil(this.destroy$),
        finalize(() => this.sendingMessage = false)
      )
      .subscribe({
        next: () => {
          this.messageContent = '';
          this.replyingTo = null;
          this.selectedAttachments = [];
          this.attachmentPreviews = [];
          this.scrollToBottom();
          this.stopTyping();
        },
        error: err => {
          console.error('Error sending message:', err);
          alert(this.i18n.t('messaging.send_failed'));
        }
      });
  }

  onMessageInput(): void {
    // Save draft
    if (this.activeConversation && this.messageContent) {
      this.messagingService.saveDraft(this.activeConversation.conversation_id, this.messageContent, this.replyingTo?.message_id)
        .pipe(takeUntil(this.destroy$))
        .subscribe();
    }

    // Typing indicator
    if (!this.isTyping && this.activeConversation) {
      this.isTyping = true;
      this.messagingService.setTyping(this.activeConversation.conversation_id, true)
        .pipe(takeUntil(this.destroy$))
        .subscribe();
    }

    // Reset typing timeout
    if (this.typingTimeout) {
      clearTimeout(this.typingTimeout);
    }
    this.typingTimeout = setTimeout(() => this.stopTyping(), 3000);
  }

  private stopTyping(): void {
    if (this.isTyping && this.activeConversation) {
      this.isTyping = false;
      this.messagingService.setTyping(this.activeConversation.conversation_id, false)
        .pipe(takeUntil(this.destroy$))
        .subscribe();
    }
  }

  onKeyDown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.sendMessage();
    }
  }

  // Reply
  replyToMessage(message: Message): void {
    this.replyingTo = message;
    this.editingMessage = null;
    this.messageInput?.nativeElement?.focus();
    this.closeContextMenu();
  }

  cancelReply(): void {
    this.replyingTo = null;
  }

  // Edit
  editMessage(message: Message): void {
    this.editingMessage = message;
    this.messageContent = message.content;
    this.replyingTo = null;
    this.messageInput?.nativeElement?.focus();
    this.closeContextMenu();
  }

  cancelEdit(): void {
    this.editingMessage = null;
    this.messageContent = '';
  }

  saveEdit(): void {
    if (!this.editingMessage || !this.messageContent.trim()) return;

    this.messagingService.editMessage(this.editingMessage.message_id, this.messageContent.trim())
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.editingMessage = null;
          this.messageContent = '';
        },
        error: err => {
          console.error('Error editing message:', err);
        }
      });
  }

  // Delete
  deleteMessage(message: Message, forEveryone: boolean = false): void {
    if (!confirm(this.i18n.t('messaging.confirm_delete_message'))) return;

    this.messagingService.deleteMessage(message.message_id, forEveryone)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        error: err => {
          console.error('Error deleting message:', err);
        }
      });
    this.closeContextMenu();
  }

  // Pin
  togglePinMessage(message: Message): void {
    this.messagingService.pinMessage(message.message_id, !message.is_pinned)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          message.is_pinned = !message.is_pinned;
        }
      });
    this.closeContextMenu();
  }

  // Forward
  openForwardModal(message: Message): void {
    this.forwardingMessage = message;
    this.forwardTargets = [];
    this.showForwardModal = true;
    this.closeContextMenu();
  }

  closeForwardModal(): void {
    this.showForwardModal = false;
    this.forwardingMessage = null;
    this.forwardTargets = [];
  }

  toggleForwardTarget(conversationId: string): void {
    const index = this.forwardTargets.indexOf(conversationId);
    if (index > -1) {
      this.forwardTargets.splice(index, 1);
    } else {
      this.forwardTargets.push(conversationId);
    }
  }

  forwardMessage(): void {
    if (!this.forwardingMessage || this.forwardTargets.length === 0) return;

    this.messagingService.forwardMessage(this.forwardingMessage.message_id, this.forwardTargets)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.closeForwardModal();
        },
        error: err => {
          console.error('Error forwarding message:', err);
        }
      });
  }

  // Reactions
  addReaction(message: Message, emoji: string): void {
    this.messagingService.addReaction(message.message_id, emoji)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          // Update local state
          const reaction = message.reactions?.find(r => r.emoji === emoji);
          if (reaction) {
            reaction.count++;
            reaction.reacted_by_me = true;
          } else {
            if (!message.reactions) message.reactions = [];
            message.reactions.push({
              emoji,
              count: 1,
              user_ids: [this.messagingService.getCurrentUserId().toString()],
              reacted_by_me: true
            });
          }
        }
      });
    this.showEmojiPicker = false;
  }

  removeReaction(message: Message, emoji: string): void {
    this.messagingService.removeReaction(message.message_id, emoji)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          const reaction = message.reactions?.find(r => r.emoji === emoji);
          if (reaction) {
            reaction.count--;
            reaction.reacted_by_me = false;
            if (reaction.count === 0) {
              message.reactions = message.reactions?.filter(r => r.emoji !== emoji);
            }
          }
        }
      });
  }

  toggleReaction(message: Message, emoji: string): void {
    const reaction = message.reactions?.find(r => r.emoji === emoji);
    if (reaction?.reacted_by_me) {
      this.removeReaction(message, emoji);
    } else {
      this.addReaction(message, emoji);
    }
  }

  // Save/Bookmark
  saveMessage(message: Message): void {
    this.messagingService.saveMessageBookmark(message.message_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe();
    this.closeContextMenu();
  }

  // Report
  reportMessage(message: Message): void {
    const reason = prompt(this.i18n.t('messaging.report_reason_prompt'));
    if (!reason) return;

    const reasons: Record<string, string> = { '1': 'spam', '2': 'harassment', '3': 'inappropriate', '4': 'other' };
    const selectedReason = reasons[reason] || 'other';

    this.messagingService.reportMessage(message.message_id, selectedReason)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          alert(this.i18n.t('messaging.report_thanks'));
        }
      });
    this.closeContextMenu();
  }

  // ==========================================
  // FILE ATTACHMENTS
  // ==========================================

  triggerFileInput(): void {
    this.fileInput?.nativeElement?.click();
  }

  onFilesSelected(event: any): void {
    const files: FileList = event.target.files;
    if (!files.length) return;

    for (let i = 0; i < files.length; i++) {
      const file = files[i];

      // Validate file using FileUploadService
      const validation = this.fileUploadService.validateFile(file);
      if (!validation.valid) {
        alert(validation.error);
        continue;
      }

      this.selectedAttachments.push(file);

      // Create preview using FileUploadService
      if (this.fileUploadService.isImage(file)) {
        this.fileUploadService.createPreviewUrl(file).then(preview => {
          this.attachmentPreviews.push(preview);
        });
      } else {
        this.attachmentPreviews.push('');
      }
    }

    // Reset file input
    event.target.value = '';
  }

  removeAttachment(index: number): void {
    this.selectedAttachments.splice(index, 1);
    this.attachmentPreviews.splice(index, 1);
  }

  // ==========================================
  // NEW CONVERSATION
  // ==========================================

  openNewConversation(type: 'direct' | 'group' | 'channel' = 'direct'): void {
    this.newConversationType = type;
    this.newConversationName = '';
    this.newConversationDescription = '';
    this.selectedUsers = [];
    this.userSearchQuery = '';
    this.userSearchResults = [];
    this.showNewConversation = true;
  }

  closeNewConversation(): void {
    this.showNewConversation = false;
  }

  searchUsers(): void {
    if (!this.userSearchQuery.trim()) {
      this.userSearchResults = [];
      return;
    }

    // Get current participant IDs to exclude from search
    const excludeIds = this.selectedUsers.map(u => u.user_id);

    this.messagingService.searchUsers(this.userSearchQuery, excludeIds)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (users) => {
          this.userSearchResults = users;
        },
        error: (err) => {
          console.error('Error searching users:', err);
          this.userSearchResults = [];
        }
      });
  }

  selectUser(user: any): void {
    if (!this.selectedUsers.find(u => u.user_id === user.user_id)) {
      this.selectedUsers.push(user);
    }
    this.userSearchQuery = '';
    this.userSearchResults = [];
  }

  removeSelectedUser(user: any): void {
    this.selectedUsers = this.selectedUsers.filter(u => u.user_id !== user.user_id);
  }

  createConversation(): void {
    if (this.newConversationType === 'direct') {
      if (this.selectedUsers.length !== 1) {
        alert(this.i18n.t('messaging.select_one_user'));
        return;
      }
      this.messagingService.startDirectMessage(this.selectedUsers[0].user_id)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: conversation => {
            this.selectConversation(conversation);
            this.closeNewConversation();
          },
          error: err => {
            console.error('Error creating conversation:', err);
          }
        });
    } else {
      if (!this.newConversationName.trim()) {
        alert(this.i18n.t('messaging.enter_group_name'));
        return;
      }
      this.messagingService.createConversation({
        type: this.newConversationType,
        name: this.newConversationName.trim(),
        description: this.newConversationDescription.trim(),
        participants: this.selectedUsers.map(u => u.user_id)
      }).pipe(takeUntil(this.destroy$))
        .subscribe({
          next: conversation => {
            this.selectConversation(conversation);
            this.closeNewConversation();
          },
          error: err => {
            console.error('Error creating conversation:', err);
          }
        });
    }
  }

  // ==========================================
  // CONVERSATION ACTIONS
  // ==========================================

  toggleMuteConversation(): void {
    if (!this.activeConversation) return;

    const newMuted = !this.activeConversation.is_muted;
    this.messagingService.updateConversationSettings(this.activeConversation.conversation_id, { is_muted: newMuted })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          if (this.activeConversation) {
            this.activeConversation.is_muted = newMuted;
          }
        }
      });
  }

  togglePinConversation(): void {
    if (!this.activeConversation) return;

    const newPinned = !this.activeConversation.is_pinned;
    this.messagingService.updateConversationSettings(this.activeConversation.conversation_id, { is_pinned: newPinned })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          if (this.activeConversation) {
            this.activeConversation.is_pinned = newPinned;
          }
          this.loadConversations();
        }
      });
  }

  archiveConversation(): void {
    if (!this.activeConversation) return;
    if (!confirm(this.i18n.t('messaging.confirm_archive'))) return;

    this.messagingService.archiveConversation(this.activeConversation.conversation_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.activeConversation = null;
          if (this.isMobile) {
            this.showConversationList = true;
          }
        }
      });
  }

  leaveConversation(): void {
    if (!this.activeConversation) return;
    if (!confirm(this.i18n.t('messaging.confirm_leave'))) return;

    this.messagingService.leaveConversation(this.activeConversation.conversation_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.activeConversation = null;
          if (this.isMobile) {
            this.showConversationList = true;
          }
        }
      });
  }

  // ==========================================
  // BROADCASTS
  // ==========================================

  openBroadcasts(): void {
    this.showBroadcasts = true;
    this.loadBroadcasts();
  }

  closeBroadcasts(): void {
    this.showBroadcasts = false;
  }

  markBroadcastRead(broadcast: Broadcast): void {
    this.messagingService.markBroadcastRead(broadcast.broadcast_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          broadcast.is_read = true;
          this.loadUnreadCounts();
        }
      });
  }

  acknowledgeBroadcast(broadcast: Broadcast): void {
    this.messagingService.acknowledgeBroadcast(broadcast.broadcast_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          broadcast.is_acknowledged = true;
          broadcast.is_read = true;
          this.loadUnreadCounts();
        }
      });
  }

  // ==========================================
  // CONTEXT MENU
  // ==========================================

  openContextMenu(event: MouseEvent, message: Message): void {
    event.preventDefault();
    this.contextMenuMessage = message;
    this.contextMenuPosition = { x: event.clientX, y: event.clientY };
    this.showContextMenu = true;
  }

  closeContextMenu(): void {
    this.showContextMenu = false;
    this.contextMenuMessage = null;
  }

  @HostListener('document:click', ['$event'])
  onDocumentClick(_event: MouseEvent): void {
    if (this.showContextMenu) {
      this.closeContextMenu();
    }
    if (this.showEmojiPicker) {
      this.showEmojiPicker = false;
    }
  }

  // ==========================================
  // SCROLL
  // ==========================================

  onMessagesScroll(event: any): void {
    const element = event.target;
    if (element.scrollTop < 100 && this.hasMoreMessages && !this.loadingMoreMessages) {
      this.loadMoreMessages();
    }
  }

  scrollToBottom(): void {
    setTimeout(() => {
      if (this.messagesContainer?.nativeElement) {
        this.messagesContainer.nativeElement.scrollTop = this.messagesContainer.nativeElement.scrollHeight;
      }
    }, 100);
  }

  // ==========================================
  // NAVIGATION
  // ==========================================

  goBackToList(): void {
    this.showConversationList = true;
    this.activeConversation = null;
  }

  toggleParticipants(): void {
    this.showParticipants = !this.showParticipants;
  }

  // ==========================================
  // HELPERS
  // ==========================================

  isOwnMessage(message: Message): boolean {
    return message.sender_id === this.messagingService.getCurrentUserId();
  }

  getConversationAvatar(conversation: Conversation): string {
    return conversation.display_avatar || conversation.avatar_url || '';
  }

  getConversationName(conversation: Conversation): string {
    return conversation.display_name || conversation.name || 'Unknown';
  }

  getInitials(name: string): string {
    return name
      .split(' ')
      .map(n => n[0])
      .join('')
      .substring(0, 2)
      .toUpperCase();
  }

  formatTime(date: string): string {
    return this.messagingService.formatMessageTime(date);
  }

  formatFileSize(bytes: number): string {
    return this.messagingService.formatFileSize(bytes);
  }

  getFileIcon(fileType: string): string {
    if (fileType.startsWith('image/')) return 'bi-file-image';
    if (fileType.startsWith('video/')) return 'bi-file-play';
    if (fileType.startsWith('audio/')) return 'bi-file-music';
    if (fileType.includes('pdf')) return 'bi-file-pdf';
    if (fileType.includes('word') || fileType.includes('document')) return 'bi-file-word';
    if (fileType.includes('excel') || fileType.includes('sheet')) return 'bi-file-excel';
    if (fileType.includes('zip') || fileType.includes('rar')) return 'bi-file-zip';
    return 'bi-file-earmark';
  }

  getOnlineStatusClass(status: string | undefined): string {
    switch (status) {
      case 'online': return 'status-online';
      case 'away': return 'status-away';
      default: return 'status-offline';
    }
  }

  getTypingText(): string {
    if (this.typingUsers.length === 0) return '';
    if (this.typingUsers.length === 1) {
      return this.i18n.t('messaging.typing_one', { name: this.typingUsers[0].first_name });
    }
    if (this.typingUsers.length === 2) {
      return this.i18n.t('messaging.typing_two', { name1: this.typingUsers[0].first_name, name2: this.typingUsers[1].first_name });
    }
    return this.i18n.t('messaging.typing_many', { count: this.typingUsers.length });
  }

  shouldShowDateDivider(index: number): boolean {
    if (index === 0) return true;
    const currentDate = new Date(this.messages[index].created_at).toDateString();
    const prevDate = new Date(this.messages[index - 1].created_at).toDateString();
    return currentDate !== prevDate;
  }

  getDateDividerText(dateString: string): string {
    const date = new Date(dateString);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    if (date.toDateString() === today.toDateString()) return this.i18n.t('messaging.today');
    if (date.toDateString() === yesterday.toDateString()) return this.i18n.t('messaging.yesterday');
    return date.toLocaleDateString([], { weekday: 'long', month: 'long', day: 'numeric' });
  }

  shouldGroupMessage(index: number): boolean {
    if (index === 0) return false;
    const current = this.messages[index];
    const prev = this.messages[index - 1];

    if (current.sender_id !== prev.sender_id) return false;

    const currentTime = new Date(current.created_at).getTime();
    const prevTime = new Date(prev.created_at).getTime();
    return (currentTime - prevTime) < 60000; // Within 1 minute
  }

  trackByConversationId(_index: number, conversation: Conversation): string {
    return conversation.conversation_id;
  }

  trackByMessageId(_index: number, message: Message): string {
    return message.message_id;
  }

  trackByParticipantId(_index: number, participant: Participant): string {
    return participant.user_id;
  }

  // Open image in new tab/modal
  openImagePreview(attachment: { file_url: string; file_name: string }): void {
    window.open(attachment.file_url, '_blank');
  }

  // ==========================================
  // CONVERSATION SETTINGS (uses updateConversation)
  // ==========================================

  openConversationSettings(): void {
    if (!this.activeConversation) return;

    this.editingConversationName = this.activeConversation.name || '';
    this.editingConversationDescription = this.activeConversation.description || '';
    this.editingConversationIsPrivate = this.activeConversation.is_private;
    this.showConversationSettings = true;
  }

  closeConversationSettings(): void {
    this.showConversationSettings = false;
  }

  saveConversationSettings(): void {
    if (!this.activeConversation) return;

    this.savingConversation = true;
    this.messagingService.updateConversation(this.activeConversation.conversation_id, {
      name: this.editingConversationName,
      description: this.editingConversationDescription,
      is_private: this.editingConversationIsPrivate
    }).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (updatedConversation) => {
          this.activeConversation = updatedConversation;
          this.savingConversation = false;
          this.closeConversationSettings();
          this.loadConversations();
        },
        error: err => {
          console.error('Error updating conversation:', err);
          this.savingConversation = false;
        }
      });
  }

  // Refresh conversation details (uses getConversation)
  refreshConversation(): void {
    if (!this.activeConversation) return;

    this.messagingService.getConversation(this.activeConversation.conversation_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (conversation) => {
          this.activeConversation = conversation;
        },
        error: err => console.error('Error refreshing conversation:', err)
      });
  }

  // ==========================================
  // PARTICIPANT MANAGEMENT (uses addParticipant, addParticipants, removeParticipant, updateParticipantRole)
  // ==========================================

  openAddParticipantsModal(): void {
    this.newParticipantSearchQuery = '';
    this.newParticipantSearchResults = [];
    this.selectedNewParticipants = [];
    this.showAddParticipantsModal = true;
  }

  closeAddParticipantsModal(): void {
    this.showAddParticipantsModal = false;
  }

  searchNewParticipants(): void {
    if (!this.newParticipantSearchQuery.trim()) {
      this.newParticipantSearchResults = [];
      return;
    }

    // Get current participant IDs and selected IDs to exclude from search
    // const currentParticipantIds = this.activeConversation?.participants.map(p => p.user_id) || [];
    const currentParticipantIds = this.activeConversation?.participants?.map(p => p.user_id) ?? [];
    const selectedIds = this.selectedNewParticipants.map(u => u.user_id);
    const excludeIds = [...currentParticipantIds, ...selectedIds];

    this.messagingService.searchUsers(this.newParticipantSearchQuery, excludeIds)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (users) => {
          this.newParticipantSearchResults = users;
        },
        error: (err) => {
          console.error('Error searching users:', err);
          this.newParticipantSearchResults = [];
        }
      });
  }

  selectNewParticipant(user: any): void {
    if (!this.selectedNewParticipants.find(u => u.user_id === user.user_id)) {
      this.selectedNewParticipants.push(user);
    }
    this.newParticipantSearchQuery = '';
    this.newParticipantSearchResults = [];
  }

  removeSelectedNewParticipant(user: any): void {
    this.selectedNewParticipants = this.selectedNewParticipants.filter(u => u.user_id !== user.user_id);
  }

  addSelectedParticipants(): void {
    if (!this.activeConversation || this.selectedNewParticipants.length === 0) return;

    this.addingParticipants = true;
    const participantIds = this.selectedNewParticipants.map(u => u.user_id);

    // Use addParticipants for multiple, addParticipant for single
    if (participantIds.length === 1) {
      this.messagingService.addParticipant(this.activeConversation.conversation_id, participantIds[0])
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.addingParticipants = false;
            this.closeAddParticipantsModal();
            this.loadParticipants();
          },
          error: err => {
            console.error('Error adding participant:', err);
            this.addingParticipants = false;
          }
        });
    } else {
      this.messagingService.addParticipants(this.activeConversation.conversation_id, participantIds)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            this.addingParticipants = false;
            this.closeAddParticipantsModal();
            this.loadParticipants();
          },
          error: err => {
            console.error('Error adding participants:', err);
            this.addingParticipants = false;
          }
        });
    }
  }

  // Remove participant (uses removeParticipant)
  removeMember(participant: Participant): void {
    if (!this.activeConversation) return;
    if (!confirm(this.i18n.t('messaging.confirm_remove_member', { name: `${participant.first_name} ${participant.last_name}` }))) return;

    this.messagingService.removeParticipant(this.activeConversation.conversation_id, participant.user_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadParticipants();
        },
        error: err => console.error('Error removing participant:', err)
      });
  }

  // Update participant role (uses updateParticipantRole)
  openParticipantRoleModal(participant: Participant): void {
    this.selectedParticipant = participant;
    this.selectedParticipantRole = participant.role;
    this.showParticipantRoleModal = true;
  }

  closeParticipantRoleModal(): void {
    this.showParticipantRoleModal = false;
    this.selectedParticipant = null;
  }

  saveParticipantRole(): void {
    if (!this.activeConversation || !this.selectedParticipant) return;

    this.messagingService.updateParticipantRole(
      this.activeConversation.conversation_id,
      this.selectedParticipant.user_id,
      this.selectedParticipantRole
    ).pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.closeParticipantRoleModal();
          this.loadParticipants();
        },
        error: err => console.error('Error updating role:', err)
      });
  }

  // ==========================================
  // MESSAGE DETAILS (uses getMessage)
  // ==========================================

  viewMessageDetails(message: Message): void {
    this.loadingMessageDetail = true;
    this.showMessageDetailModal = true;
    this.selectedMessageDetail = message;

    // Fetch fresh message data
    this.messagingService.getMessage(message.message_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (msg) => {
          this.selectedMessageDetail = msg;
          this.loadingMessageDetail = false;
        },
        error: err => {
          console.error('Error loading message:', err);
          this.loadingMessageDetail = false;
        }
      });

    this.closeContextMenu();
  }

  closeMessageDetailModal(): void {
    this.showMessageDetailModal = false;
    this.selectedMessageDetail = null;
  }

  // ==========================================
  // READ RECEIPTS (uses getReadReceipts)
  // ==========================================

  viewReadReceipts(message: Message): void {
    this.selectedMessageForReceipts = message;
    this.readReceipts = [];
    this.loadingReadReceipts = true;
    this.showReadReceiptsModal = true;

    this.messagingService.getReadReceipts(message.message_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (receipts) => {
          this.readReceipts = receipts;
          this.loadingReadReceipts = false;
        },
        error: err => {
          console.error('Error loading read receipts:', err);
          this.loadingReadReceipts = false;
        }
      });

    this.closeContextMenu();
  }

  closeReadReceiptsModal(): void {
    this.showReadReceiptsModal = false;
    this.readReceipts = [];
    this.selectedMessageForReceipts = null;
  }

  // ==========================================
  // MESSAGE SEARCH (uses searchMessages)
  // ==========================================

  openMessageSearch(): void {
    this.messageSearchQuery = '';
    this.messageSearchResults = [];
    this.searchFromDate = '';
    this.searchToDate = '';
    this.searchHasAttachments = false;
    this.showMessageSearchModal = true;
  }

  closeMessageSearch(): void {
    this.showMessageSearchModal = false;
    this.messageSearchResults = [];
  }

  performMessageSearch(): void {
    if (!this.messageSearchQuery.trim()) return;

    this.searchingMessages = true;
    const options: any = {};

    if (this.activeConversation) {
      options.conversation_id = this.activeConversation.conversation_id;
    }
    if (this.searchFromDate) {
      options.from_date = this.searchFromDate;
    }
    if (this.searchToDate) {
      options.to_date = this.searchToDate;
    }
    if (this.searchHasAttachments) {
      options.has_attachments = true;
    }

    this.messagingService.searchMessages(this.messageSearchQuery, options)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (results) => {
          this.messageSearchResults = results;
          this.searchingMessages = false;
        },
        error: err => {
          console.error('Error searching messages:', err);
          this.searchingMessages = false;
        }
      });
  }

  jumpToSearchResult(message: Message): void {
    // Find the conversation and select it
    const conversation = this.conversations.find(c => c.conversation_id === message.conversation_id);
    if (conversation) {
      this.selectConversation(conversation);
      this.closeMessageSearch();
      // Scroll to message after loading
      setTimeout(() => {
        const messageElement = document.getElementById(`message-${message.message_id}`);
        if (messageElement) {
          messageElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
          messageElement.classList.add('highlight');
          setTimeout(() => messageElement.classList.remove('highlight'), 2000);
        }
      }, 500);
    }
  }

  // ==========================================
  // DRAFT MANAGEMENT (uses deleteDraft)
  // ==========================================

  clearDraft(): void {
    if (!this.activeConversation) return;

    this.messagingService.deleteDraft(this.activeConversation.conversation_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.messageContent = '';
        },
        error: err => console.error('Error clearing draft:', err)
      });
  }

  // ==========================================
  // BROADCAST DETAILS (uses getBroadcast)
  // ==========================================

  viewBroadcastDetails(broadcast: Broadcast): void {
    this.loadingBroadcast = true;
    this.showBroadcastDetailModal = true;
    this.selectedBroadcast = broadcast;

    this.messagingService.getBroadcast(broadcast.broadcast_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (b) => {
          this.selectedBroadcast = b;
          this.loadingBroadcast = false;
          // Mark as read
          if (!b.is_read) {
            this.markBroadcastRead(b);
          }
        },
        error: err => {
          console.error('Error loading broadcast:', err);
          this.loadingBroadcast = false;
        }
      });
  }

  closeBroadcastDetailModal(): void {
    this.showBroadcastDetailModal = false;
    this.selectedBroadcast = null;
  }

  // ==========================================
  // BLOCK USER (uses blockUser)
  // ==========================================

  openBlockUserModal(participant: Participant): void {
    this.userToBlock = participant;
    this.blockReason = '';
    this.showBlockUserModal = true;
  }

  closeBlockUserModal(): void {
    this.showBlockUserModal = false;
    this.userToBlock = null;
    this.blockReason = '';
  }

  confirmBlockUser(): void {
    if (!this.userToBlock) return;

    this.blockingUser = true;
    this.messagingService.blockUser(this.userToBlock.user_id, this.blockReason || undefined)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.blockingUser = false;
          this.closeBlockUserModal();
          // Refresh conversation list and participants
          this.loadConversations();
          if (this.activeConversation) {
            this.loadParticipants();
          }
        },
        error: err => {
          console.error('Error blocking user:', err);
          this.blockingUser = false;
        }
      });
  }

  // Quick block from context menu
  blockMessageSender(message: Message): void {
    if (message.sender_id === this.messagingService.getCurrentUserId()) return;

    const participant = this.participants.find(p => p.user_id === message.sender_id);
    if (participant) {
      this.openBlockUserModal(participant);
    } else {
      // Create minimal participant object
      this.userToBlock = {
        user_id: message.sender_id,
        first_name: message.sender_first_name,
        last_name: message.sender_last_name,
        email: '',
        role: 'member',
        can_post: false,
        can_add_members: false,
        can_remove_members: false,
        can_edit_settings: false,
        can_pin_messages: false,
        can_delete_messages: false,
        is_muted: false,
        joined_at: '',
        profile_image: message.sender_avatar
      };
      this.blockReason = '';
      this.showBlockUserModal = true;
    }
    this.closeContextMenu();
  }

  // ==========================================
  // ADDITIONAL HELPERS
  // ==========================================

  canManageParticipants(): boolean {
    if (!this.activeConversation) return false;
    return ['owner', 'admin', 'moderator'].includes(this.activeConversation.my_role);
  }

  canEditConversation(): boolean {
    if (!this.activeConversation) return false;
    return ['owner', 'admin'].includes(this.activeConversation.my_role);
  }

  isGroupOrChannel(): boolean {
    if (!this.activeConversation) return false;
    return ['group', 'channel'].includes(this.activeConversation.type);
  }

  getRoleBadgeClass(role: string): string {
    switch (role) {
      case 'owner': return 'bg-danger';
      case 'admin': return 'bg-primary';
      case 'moderator': return 'bg-warning text-dark';
      default: return 'bg-secondary';
    }
  }

  formatDateTime(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleString([], {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  }
}
