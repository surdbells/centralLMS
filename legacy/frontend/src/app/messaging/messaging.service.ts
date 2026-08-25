// messaging/messaging.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, BehaviorSubject, Subject } from 'rxjs';
import { map, tap } from 'rxjs/operators';
import {GlobalComponent} from '../global-component';

// ==========================================
// INTERFACES
// ==========================================

export interface Conversation {
  conversation_id: number;
  tenant_id: number;
  type: 'direct' | 'group' | 'channel' | 'announcement';
  name: string | null;
  description: string | null;
  avatar_url: string | null;
  is_private: boolean;
  is_readonly: boolean;
  allow_reactions: boolean;
  allow_replies: boolean;
  allow_attachments: boolean;
  linked_entity_type: string | null;
  linked_entity_id: number | null;
  last_message_id: number | null;
  last_message_at: string | null;
  message_count: number;
  created_by: number;
  created_at: string;
  // Participant info
  my_role: 'member' | 'admin' | 'owner' | 'moderator';
  is_muted: boolean;
  is_pinned: boolean;
  is_archived: boolean;
  unread_count: number;
  last_read_message_id: number | null;
  notify_all_messages: boolean;
  notify_mentions_only: boolean;
  // Display
  display_name?: string;
  display_avatar?: string | null;
  other_user?: Participant;
  other_user_id?: number;
  online_status?: 'online' | 'away' | 'offline';
  // Last message preview
  last_message_content?: string;
  last_message_type?: string;
  last_message_sender_id?: number;
  sender_first_name?: string;
  sender_last_name?: string;
  participants?: Participant[];
}

export interface Participant {
  user_id: number;
  role: 'member' | 'admin' | 'owner' | 'moderator';
  can_post: boolean;
  can_add_members: boolean;
  can_remove_members: boolean;
  can_edit_settings: boolean;
  can_pin_messages: boolean;
  can_delete_messages: boolean;
  is_muted: boolean;
  joined_at: string;
  first_name: string;
  last_name: string;
  email: string;
  profile_image: string | null;
  online_status?: 'online' | 'away' | 'offline';
  last_active_at?: string;
}

export interface Message {
  message_id: number;
  conversation_id: number;
  sender_id: number;
  tenant_id: number;
  content: string;
  content_html: string;
  content_plain: string;
  message_type: 'text' | 'file' | 'image' | 'video' | 'audio' | 'system' | 'announcement';
  reply_to_id: number | null;
  thread_root_id: number | null;
  thread_reply_count: number;
  thread_last_reply_at: string | null;
  forwarded_from_id: number | null;
  is_edited: boolean;
  edited_at: string | null;
  is_deleted: boolean;
  is_pinned: boolean;
  pinned_at: string | null;
  priority: 'normal' | 'important' | 'urgent';
  metadata: Record<string, any> | null;
  created_at: string;
  // Sender info
  sender_first_name: string;
  sender_last_name: string;
  sender_avatar: string | null;
  // Related
  attachments?: MessageAttachment[];
  reactions?: MessageReaction[];
  reply_to?: MessagePreview;
}

export interface MessageAttachment {
  attachment_id: number;
  message_id: number;
  file_name: string;
  file_type: string;
  file_size: number;
  file_url: string;
  thumbnail_url: string | null;
  width: number | null;
  height: number | null;
  duration: number | null;
  metadata: Record<string, any> | null;
}

export interface MessageReaction {
  emoji: string;
  count: number;
  user_ids: string[];
  reacted_by_me: boolean;
}

export interface MessagePreview {
  message_id: number;
  content: string;
  sender_id: number;
  first_name: string;
  last_name: string;
}

export interface ReadReceipt {
  user_id: number;
  read_at: string;
  first_name: string;
  last_name: string;
  profile_image: string | null;
}

export interface TypingUser {
  user_id: number;
  first_name: string;
  last_name: string;
}

export interface MessageDraft {
  draft_id: number;
  conversation_id: number;
  user_id: number;
  content: string;
  reply_to_id: number | null;
  created_at: string;
  updated_at: string | null;
}

export interface SavedMessage {
  id: number;
  message_id: number;
  user_id: number;
  note: string | null;
  created_at: string;
  content: string;
  message_created_at: string;
  conversation_id: number;
  conversation_name: string;
  sender_first_name: string;
  sender_last_name: string;
}

export interface BroadcastTargetCriteria {
  roles?: number[];
  departments?: number[];
  course_id?: number | null;
  user_ids?: number[];
  [key: string]: any; // Allow additional properties
}

export interface Broadcast {
  broadcast_id: number;
  tenant_id: number;
  title: string;
  content: string;
  content_html: string | null;
  target_type: 'all' | 'role' | 'department' | 'course' | 'custom';
  target_criteria: BroadcastTargetCriteria | null;
  priority: 'normal' | 'important' | 'urgent';
  requires_acknowledgment: boolean;
  scheduled_at: string | null;
  expires_at: string | null;
  status: 'draft' | 'scheduled' | 'sent' | 'cancelled';
  sent_at: string | null;
  recipients_count: number;
  read_count: number;
  acknowledged_count: number;
  created_by: number;
  created_at: string;
  // User specific
  is_read?: boolean;
  is_acknowledged?: boolean;
  read_at?: string;
  sender_first_name?: string;
  sender_last_name?: string;
}

export interface BlockedUser {
  user_id: number;
  blocked_user_id: number;
  reason: string | null;
  created_at: string;
  first_name: string;
  last_name: string;
  email: string;
  profile_image: string | null;
}

export interface MessagingSettings {
  allow_direct_messages: boolean;
  allow_group_invites: boolean;
  show_online_status: boolean;
  show_read_receipts: boolean;
  email_digest_frequency: 'never' | 'instant' | 'hourly' | 'daily' | 'weekly';
  quiet_hours_start: string | null;
  quiet_hours_end: string | null;
  auto_reply_enabled: boolean;
  auto_reply_message: string | null;
}

export interface UnreadCounts {
  total_unread: number;
  unread_mentions: number;
  unread_broadcasts: number;
}

export interface SearchOptions {
  conversation_id?: number;
  sender_id?: number;
  from_date?: string;
  to_date?: string;
  has_attachments?: boolean;
  limit?: number;
}

export interface ConversationFilters {
  type?: string;
  is_archived?: number;
  search?: string;
  limit?: number;
  offset?: number;
}

export interface MessageFilters {
  before_id?: number;
  after_id?: number;
  thread_root_id?: number;
  pinned_only?: boolean;
  limit?: number;
}

interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

// ==========================================
// SERVICE
// ==========================================

@Injectable({
  providedIn: 'root'
})
export class MessagingService {
  private apiUrl = GlobalComponent.internalMessaging;
  // Real-time state management
  private conversationsSubject = new BehaviorSubject<Conversation[]>([]);
  private activeConversationSubject = new BehaviorSubject<Conversation | null>(null);
  private messagesSubject = new BehaviorSubject<Message[]>([]);
  private unreadCountsSubject = new BehaviorSubject<UnreadCounts>({ total_unread: 0, unread_mentions: 0, unread_broadcasts: 0 });
  private typingUsersSubject = new BehaviorSubject<TypingUser[]>([]);

  // Observables
  public conversations$ = this.conversationsSubject.asObservable();
  public activeConversation$ = this.activeConversationSubject.asObservable();
  public messages$ = this.messagesSubject.asObservable();
  public unreadCounts$ = this.unreadCountsSubject.asObservable();
  public typingUsers$ = this.typingUsersSubject.asObservable();

  // Events for real-time updates
  public newMessage$ = new Subject<Message>();
  public messageUpdated$ = new Subject<Message>();
  public messageDeleted$ = new Subject<number>();
  public reactionUpdated$ = new Subject<{ messageId: number; reactions: MessageReaction[] }>();

  constructor(private http: HttpClient) {}
  session_data: any = ""
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };
  private getAuthPayload(): any {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = JSON.parse(atob(this.session_data));
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
    };
  }

  // ==========================================
  // CONVERSATIONS
  // ==========================================

  createConversation(data: {
    type: 'direct' | 'group' | 'channel';
    name?: string;
    description?: string;
    participants?: number[];
    is_private?: boolean;
    avatar_url?: string;
  }): Observable<Conversation> {
    return this.http.post<ApiResponse<Conversation>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'create-conversation',
      ...data
    }).pipe(
      map(res => res.data),
      tap(conv => this.addConversationToList(conv))
    );
  }

  startDirectMessage(recipientId: number): Observable<Conversation> {
    return this.http.post<ApiResponse<Conversation>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'start-direct-message',
      recipient_id: recipientId
    }).pipe(
      map(res => res.data),
      tap(conv => this.addConversationToList(conv))
    );
  }

  getConversation(conversationId: number): Observable<Conversation> {
    return this.http.post<ApiResponse<Conversation>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-conversation',
      conversation_id: conversationId
    }).pipe(map(res => res.data));
  }

  getConversations(filters?: ConversationFilters): Observable<Conversation[]> {
    return this.http.post<ApiResponse<Conversation[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-conversations',
      ...filters
    }).pipe(
      map(res => res.data),
      tap(convs => this.conversationsSubject.next(convs))
    );
  }

  updateConversation(conversationId: number, data: Partial<Conversation>): Observable<Conversation> {
    return this.http.post<ApiResponse<Conversation>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-conversation',
      conversation_id: conversationId,
      ...data
    }).pipe(
      map(res => res.data),
      tap(conv => this.updateConversationInList(conv))
    );
  }

  archiveConversation(conversationId: number, archive: boolean = true): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'archive-conversation',
      conversation_id: conversationId,
      archive
    }).pipe(
      map(res => res.data),
      tap(() => this.removeConversationFromList(conversationId))
    );
  }

  leaveConversation(conversationId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'leave-conversation',
      conversation_id: conversationId
    }).pipe(
      map(res => res.data),
      tap(() => this.removeConversationFromList(conversationId))
    );
  }

  setActiveConversation(conversation: Conversation | null): void {
    this.activeConversationSubject.next(conversation);
    if (conversation) {
      this.messagesSubject.next([]);
    }
  }

  // ==========================================
  // PARTICIPANTS
  // ==========================================

  addParticipant(conversationId: number, participantId: number, role: string = 'member'): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'add-participant',
      conversation_id: conversationId,
      participant_id: participantId,
      role
    }).pipe(map(res => res.data));
  }

  addParticipants(conversationId: number, participantIds: number[]): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'add-participants',
      conversation_id: conversationId,
      participant_ids: participantIds
    }).pipe(map(res => res.data));
  }

  removeParticipant(conversationId: number, participantId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'remove-participant',
      conversation_id: conversationId,
      participant_id: participantId
    }).pipe(map(res => res.data));
  }

  getParticipants(conversationId: number): Observable<Participant[]> {
    return this.http.post<ApiResponse<Participant[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-participants',
      conversation_id: conversationId
    }).pipe(map(res => res.data));
  }

  updateParticipantRole(conversationId: number, participantId: number, role: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-participant-role',
      conversation_id: conversationId,
      participant_id: participantId,
      role
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // MESSAGES
  // ==========================================

  sendMessage(conversationId: number, data: {
    content: string;
    message_type?: string;
    reply_to_id?: number;
    thread_root_id?: number;
    attachments?: Partial<MessageAttachment>[];
    metadata?: Record<string, any>;
  }): Observable<Message> {
    return this.http.post<ApiResponse<Message>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'send-message',
      conversation_id: conversationId,
      ...data
    }).pipe(
      map(res => res.data),
      tap(msg => {
        this.addMessageToList(msg);
        this.newMessage$.next(msg);
        this.updateConversationLastMessage(conversationId, msg);
      })
    );
  }

  getMessage(messageId: number): Observable<Message> {
    return this.http.post<ApiResponse<Message>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-message',
      message_id: messageId
    }).pipe(map(res => res.data));
  }

  getMessages(conversationId: number, filters?: MessageFilters): Observable<Message[]> {
    return this.http.post<ApiResponse<Message[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-messages',
      conversation_id: conversationId,
      ...filters
    }).pipe(
      map(res => res.data),
      tap(msgs => {
        if (filters?.after_id) {
          // Append new messages
          const current = this.messagesSubject.value;
          this.messagesSubject.next([...current, ...msgs]);
        } else if (filters?.before_id) {
          // Prepend older messages
          const current = this.messagesSubject.value;
          this.messagesSubject.next([...msgs, ...current]);
        } else {
          this.messagesSubject.next(msgs);
        }
      })
    );
  }

  editMessage(messageId: number, content: string): Observable<Message> {
    return this.http.post<ApiResponse<Message>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'edit-message',
      message_id: messageId,
      content
    }).pipe(
      map(res => res.data),
      tap(msg => {
        this.updateMessageInList(msg);
        this.messageUpdated$.next(msg);
      })
    );
  }

  deleteMessage(messageId: number, forEveryone: boolean = false): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'delete-message',
      message_id: messageId,
      for_everyone: forEveryone
    }).pipe(
      map(res => res.data),
      tap(() => {
        this.removeMessageFromList(messageId);
        this.messageDeleted$.next(messageId);
      })
    );
  }

  pinMessage(messageId: number, pin: boolean = true): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'pin-message',
      message_id: messageId,
      pin
    }).pipe(map(res => res.data));
  }

  forwardMessage(messageId: number, conversationIds: number[]): Observable<Message[]> {
    return this.http.post<ApiResponse<Message[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'forward-message',
      message_id: messageId,
      conversation_ids: conversationIds
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // REACTIONS
  // ==========================================

  addReaction(messageId: number, emoji: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'add-reaction',
      message_id: messageId,
      emoji
    }).pipe(map(res => res.data));
  }

  removeReaction(messageId: number, emoji: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'remove-reaction',
      message_id: messageId,
      emoji
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // READ RECEIPTS & TYPING
  // ==========================================

  markAsRead(conversationId: number, upToMessageId?: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'mark-as-read',
      conversation_id: conversationId,
      up_to_message_id: upToMessageId
    }).pipe(
      map(res => res.data),
      tap(() => this.updateConversationUnreadCount(conversationId, 0))
    );
  }

  getReadReceipts(messageId: number): Observable<ReadReceipt[]> {
    return this.http.post<ApiResponse<ReadReceipt[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-read-receipts',
      message_id: messageId
    }).pipe(map(res => res.data));
  }

  setTyping(conversationId: number, typing: boolean = true): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'set-typing',
      conversation_id: conversationId,
      typing
    }).pipe(map(res => res.data));
  }

  getTypingUsers(conversationId: number): Observable<TypingUser[]> {
    return this.http.post<ApiResponse<TypingUser[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-typing-users',
      conversation_id: conversationId
    }).pipe(
      map(res => res.data),
      tap(users => this.typingUsersSubject.next(users))
    );
  }

  // ==========================================
  // SEARCH
  // ==========================================

  searchMessages(query: string, options?: SearchOptions): Observable<Message[]> {
    return this.http.post<ApiResponse<Message[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'search-messages',
      query,
      ...options
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // DRAFTS
  // ==========================================

  saveDraft(conversationId: number, content: string, replyToId?: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'save-draft',
      conversation_id: conversationId,
      content,
      reply_to_id: replyToId
    }).pipe(map(res => res.data));
  }

  getDraft(conversationId: number): Observable<MessageDraft | null> {
    return this.http.post<ApiResponse<MessageDraft | null>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-draft',
      conversation_id: conversationId
    }).pipe(map(res => res.data));
  }

  deleteDraft(conversationId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'delete-draft',
      conversation_id: conversationId
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // SAVED MESSAGES
  // ==========================================

  saveMessageBookmark(messageId: number, note?: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'save-message',
      message_id: messageId,
      note
    }).pipe(map(res => res.data));
  }

  unsaveMessage(messageId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'unsave-message',
      message_id: messageId
    }).pipe(map(res => res.data));
  }

  getSavedMessages(limit: number = 50, offset: number = 0): Observable<SavedMessage[]> {
    return this.http.post<ApiResponse<SavedMessage[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-saved-messages',
      limit,
      offset
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // BROADCASTS
  // ==========================================

  createBroadcast(data: Partial<Broadcast>): Observable<Broadcast> {
    return this.http.post<ApiResponse<Broadcast>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'create-broadcast',
      ...data
    }).pipe(map(res => res.data));
  }

  sendBroadcast(broadcastId: number): Observable<Broadcast> {
    return this.http.post<ApiResponse<Broadcast>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'send-broadcast',
      broadcast_id: broadcastId
    }).pipe(map(res => res.data));
  }

  getBroadcast(broadcastId: number): Observable<Broadcast> {
    return this.http.post<ApiResponse<Broadcast>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-broadcast',
      broadcast_id: broadcastId
    }).pipe(map(res => res.data));
  }

  getMyBroadcasts(unreadOnly: boolean = false): Observable<Broadcast[]> {
    return this.http.post<ApiResponse<Broadcast[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-my-broadcasts',
      unread_only: unreadOnly
    }).pipe(map(res => res.data));
  }

  markBroadcastRead(broadcastId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'mark-broadcast-read',
      broadcast_id: broadcastId
    }).pipe(map(res => res.data));
  }

  acknowledgeBroadcast(broadcastId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'acknowledge-broadcast',
      broadcast_id: broadcastId
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // BLOCKING
  // ==========================================

  blockUser(userId: number, reason?: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'block-user',
      blocked_user_id: userId,
      reason
    }).pipe(map(res => res.data));
  }

  unblockUser(userId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'unblock-user',
      blocked_user_id: userId
    }).pipe(map(res => res.data));
  }

  getBlockedUsers(): Observable<BlockedUser[]> {
    return this.http.post<ApiResponse<BlockedUser[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-blocked-users'
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // REPORTING
  // ==========================================

  reportMessage(messageId: number, reason: string, description?: string): Observable<{ report_id: number }> {
    return this.http.post<ApiResponse<{ report_id: number }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'report-message',
      message_id: messageId,
      reason,
      description
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // SETTINGS
  // ==========================================

  getSettings(): Observable<MessagingSettings> {
    return this.http.post<ApiResponse<MessagingSettings>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-settings'
    }).pipe(map(res => res.data));
  }

  updateSettings(settings: Partial<MessagingSettings>): Observable<MessagingSettings> {
    return this.http.post<ApiResponse<MessagingSettings>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-settings',
      ...settings
    }).pipe(map(res => res.data));
  }

  updateConversationSettings(conversationId: number, settings: {
    is_muted?: boolean;
    muted_until?: string;
    is_pinned?: boolean;
    notify_all_messages?: boolean;
    notify_mentions_only?: boolean;
    notify_email?: boolean;
    notify_push?: boolean;
  }): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-conversation-settings',
      conversation_id: conversationId,
      ...settings
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // USER SEARCH
  // ==========================================

  searchUsers(query: string, excludeIds: number[] = []): Observable<any[]> {
    return this.http.post<ApiResponse<any[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'search-users',
      query,
      exclude_ids: excludeIds
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // STATISTICS
  // ==========================================

  getUnreadCounts(): Observable<UnreadCounts> {
    return this.http.post<ApiResponse<UnreadCounts>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-unread-counts'
    }).pipe(
      map(res => res.data),
      tap(counts => this.unreadCountsSubject.next(counts))
    );
  }

  // ==========================================
  // STATE HELPERS
  // ==========================================

  private addConversationToList(conversation: Conversation): void {
    const current = this.conversationsSubject.value;
    const exists = current.find(c => c.conversation_id === conversation.conversation_id);
    if (!exists) {
      this.conversationsSubject.next([conversation, ...current]);
    }
  }

  private updateConversationInList(conversation: Conversation): void {
    const current = this.conversationsSubject.value;
    const index = current.findIndex(c => c.conversation_id === conversation.conversation_id);
    if (index > -1) {
      current[index] = { ...current[index], ...conversation };
      this.conversationsSubject.next([...current]);
    }
  }

  private removeConversationFromList(conversationId: number): void {
    const current = this.conversationsSubject.value;
    this.conversationsSubject.next(current.filter(c => c.conversation_id !== conversationId));
  }

  private updateConversationLastMessage(conversationId: number, message: Message): void {
    const current = this.conversationsSubject.value;
    const index = current.findIndex(c => c.conversation_id === conversationId);
    if (index > -1) {
      current[index] = {
        ...current[index],
        last_message_id: message.message_id,
        last_message_at: message.created_at,
        last_message_content: message.content,
        last_message_type: message.message_type,
        last_message_sender_id: message.sender_id,
        message_count: current[index].message_count + 1
      };
      // Move to top
      const updated = current.splice(index, 1)[0];
      this.conversationsSubject.next([updated, ...current]);
    }
  }

  private updateConversationUnreadCount(conversationId: number, count: number): void {
    const current = this.conversationsSubject.value;
    const index = current.findIndex(c => c.conversation_id === conversationId);
    if (index > -1) {
      current[index] = { ...current[index], unread_count: count };
      this.conversationsSubject.next([...current]);
    }
    // Update total counts
    this.getUnreadCounts().subscribe();
  }

  private addMessageToList(message: Message): void {
    const current = this.messagesSubject.value;
    this.messagesSubject.next([...current, message]);
  }

  private updateMessageInList(message: Message): void {
    const current = this.messagesSubject.value;
    const index = current.findIndex(m => m.message_id === message.message_id);
    if (index > -1) {
      current[index] = message;
      this.messagesSubject.next([...current]);
    }
  }

  private removeMessageFromList(messageId: number): void {
    const current = this.messagesSubject.value;
    this.messagesSubject.next(current.filter(m => m.message_id !== messageId));
  }

  // ==========================================
  // UTILITY
  // ==========================================

  getCurrentUserId(): number {
    return parseInt(localStorage.getItem('user_id') || '0', 10);
  }

  formatMessageTime(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now.getTime() - date.getTime();
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));

    if (days === 0) {
      return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } else if (days === 1) {
      return 'Yesterday';
    } else if (days < 7) {
      return date.toLocaleDateString([], { weekday: 'short' });
    } else {
      return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }
  }

  formatFileSize(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }
}
