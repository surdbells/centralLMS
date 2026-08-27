// messaging/messaging.service.ts

import { Injectable, inject } from '@angular/core';
import { Observable, BehaviorSubject, Subject, of, throwError } from 'rxjs';
import { map, tap } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../core/api';
import { SessionStore } from '../core/auth/session.store';

// ==========================================
// INTERFACES
// ==========================================

export interface Conversation {
  conversation_id: string;
  tenant_id: string;
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
  linked_entity_id: string | null;
  last_message_id: string | null;
  last_message_at: string | null;
  message_count: number;
  created_by: string;
  created_at: string;
  // Participant info
  my_role: 'member' | 'admin' | 'owner' | 'moderator';
  is_muted: boolean;
  is_pinned: boolean;
  is_archived: boolean;
  unread_count: number;
  last_read_message_id: string | null;
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
  user_id: string;
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
  message_id: string;
  conversation_id: string;
  sender_id: string;
  tenant_id: string;
  content: string;
  content_html: string;
  content_plain: string;
  message_type: 'text' | 'file' | 'image' | 'video' | 'audio' | 'system' | 'announcement';
  reply_to_id: string | null;
  thread_root_id: string | null;
  thread_reply_count: number;
  thread_last_reply_at: string | null;
  forwarded_from_id: string | null;
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
  attachment_id: string;
  message_id: string;
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
  message_id: string;
  content: string;
  sender_id: string;
  first_name: string;
  last_name: string;
}

export interface ReadReceipt {
  user_id: string;
  read_at: string;
  first_name: string;
  last_name: string;
  profile_image: string | null;
}

export interface TypingUser {
  user_id: string;
  first_name: string;
  last_name: string;
}

export interface MessageDraft {
  draft_id: string;
  conversation_id: string;
  user_id: string;
  content: string;
  reply_to_id: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface SavedMessage {
  id: number;
  message_id: string;
  user_id: string;
  note: string | null;
  created_at: string;
  content: string;
  message_created_at: string;
  conversation_id: string;
  conversation_name: string;
  sender_first_name: string;
  sender_last_name: string;
}

export interface BroadcastTargetCriteria {
  roles?: number[];
  departments?: number[];
  course_id?: number | null;
  user_ids?: string[];
  [key: string]: any; // Allow additional properties
}

export interface Broadcast {
  broadcast_id: string;
  tenant_id: string;
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
  created_by: string;
  created_at: string;
  // User specific
  is_read?: boolean;
  is_acknowledged?: boolean;
  read_at?: string;
  sender_first_name?: string;
  sender_last_name?: string;
}

export interface BlockedUser {
  user_id: string;
  blocked_user_id: string;
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
  conversation_id?: string;
  sender_id?: string;
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
  thread_root_id?: string;
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

/**
 * Internal messaging, over the new API.
 *
 * It posted to one legacy endpoint with fifty-odd `?action=` variants and
 * `token`, `user_id` and `tenant_id` decoded from `sessionStorage.SESSION`.
 * Four components depend on this service, so the method names, the observable
 * streams and the shapes they carry are unchanged.
 *
 * **Rather more than half of those fifty methods have no server.** The schema
 * holds conversations, participants and messages — that is the whole of it.
 * Reactions, drafts, pins, bookmarks, blocking, typing indicators, per-user
 * read receipts, broadcasts, forwarding, reporting and message search each had
 * a legacy action and no table, and are grouped at the bottom of this file
 * saying so. Three screens are built almost entirely on them.
 *
 * They fail loudly rather than resolving empty, for the reason that runs
 * through this migration: a saved-messages screen that renders nothing because
 * the feature was never built looks exactly like one where you have saved
 * nothing, and the difference is the only thing the person looking at it wants
 * to know.
 */
@Injectable({
  providedIn: 'root',
})
export class MessagingService {
  private readonly api = inject(ApiClient);
  private readonly session = inject(SessionStore);

  // State
  private conversationsSubject = new BehaviorSubject<Conversation[]>([]);
  private activeConversationSubject = new BehaviorSubject<Conversation | null>(null);
  private messagesSubject = new BehaviorSubject<Message[]>([]);
  private unreadCountsSubject = new BehaviorSubject<UnreadCounts>({
    total_unread: 0,
    unread_mentions: 0,
    unread_broadcasts: 0,
  });
  private typingUsersSubject = new BehaviorSubject<TypingUser[]>([]);

  public conversations$ = this.conversationsSubject.asObservable();
  public activeConversation$ = this.activeConversationSubject.asObservable();
  public messages$ = this.messagesSubject.asObservable();
  public unreadCounts$ = this.unreadCountsSubject.asObservable();
  public typingUsers$ = this.typingUsersSubject.asObservable();

  public newMessage$ = new Subject<Message>();
  public messageUpdated$ = new Subject<Message>();
  public reactionUpdated$ = new Subject<{ message_id: string; reactions: MessageReaction[] }>();

  /** Whoever is signed in, from the token rather than a decoded blob. */
  getCurrentUserId(): string {
    return this.session.session()?.userId ?? '';
  }

  // ==========================================
  // CONVERSATIONS
  // ==========================================

  getConversations(
    filters?: { search?: string; type?: string; is_archived?: number },
  ): Observable<Conversation[]> {
    return this.api
      .getPaged<ConversationPayload>(Endpoints.conversations.list, {
        search: filters?.search,
        kind: filters?.type,
        perPage: 100,
      })
      .pipe(
        map((page) => page.items.map((row) => toConversation(row, this.getCurrentUserId()))),
        tap((conversations) => this.conversationsSubject.next(conversations)),
      );
  }

  getConversation(conversationId: string): Observable<Conversation> {
    return this.api
      .get<ConversationPayload>(Endpoints.conversations.show(conversationId))
      .pipe(map((row) => toConversation(row, this.getCurrentUserId())));
  }

  createConversation(data: {
    type?: string;
    name?: string;
    description?: string;
    participant_ids?: string[];
    /** The screen's other name for the same list. */
    participants?: string[];
  }): Observable<Conversation> {
    return this.api
      .post<ConversationPayload>(Endpoints.conversations.start, {
        kind: data.type ?? 'group',
        title: data.name ?? null,
        description: data.description ?? null,
        participantIds: data.participant_ids ?? data.participants ?? [],
      })
      .pipe(map((row) => toConversation(row, this.getCurrentUserId())));
  }

  /**
   * Opens the conversation with one other person, making it if need be.
   *
   * The API settles which of those it is: starting a direct conversation that
   * already exists returns the existing one rather than a second copy, which
   * is the only way two people cannot end up with two threads between them.
   */
  startDirectMessage(userId: string): Observable<Conversation> {
    return this.api
      .post<ConversationPayload>(Endpoints.conversations.start, {
        kind: 'direct',
        participantIds: [userId],
      })
      .pipe(map((row) => toConversation(row, this.getCurrentUserId())));
  }

  updateConversation(conversationId: string, data: Record<string, unknown>): Observable<Conversation> {
    return this.api
      .patch<ConversationPayload>(Endpoints.conversations.update(conversationId), {
        title: data['name'],
        description: data['description'],
      })
      .pipe(map((row) => toConversation(row, this.getCurrentUserId())));
  }

  updateConversationSettings(
    conversationId: string,
    settings: Record<string, unknown>,
  ): Observable<Conversation> {
    // Muting is a per-participant setting and the API takes it on the same
    // PATCH, so this is the conversation update under another name.
    return this.api
      .patch<ConversationPayload>(Endpoints.conversations.update(conversationId), settings)
      .pipe(map((row) => toConversation(row, this.getCurrentUserId())));
  }

  setActiveConversation(conversation: Conversation | null): void {
    this.activeConversationSubject.next(conversation);
  }

  markAsRead(conversationId: string, _messageId?: string): Observable<void> {
    return this.api
      .post(Endpoints.conversations.read(conversationId), {})
      .pipe(
        map(() => undefined),
        tap(() => this.getUnreadCounts().subscribe({ error: () => undefined })),
      );
  }

  getUnreadCounts(): Observable<UnreadCounts> {
    return this.api
      .get<{ total: number; conversations?: number }>(Endpoints.conversations.unread)
      .pipe(
        map((counts) => ({
          total_unread: counts.total ?? 0,
          // Mentions and broadcasts are not modelled, so they are zero rather
          // than a number invented from the total.
          unread_mentions: 0,
          unread_broadcasts: 0,
        })),
        tap((counts) => this.unreadCountsSubject.next(counts)),
      );
  }

  leaveConversation(conversationId: string): Observable<void> {
    return this.removeParticipant(conversationId, this.getCurrentUserId());
  }

  // ==========================================
  // PARTICIPANTS
  // ==========================================

  getParticipants(conversationId: string): Observable<Participant[]> {
    return this.api
      .get<ConversationPayload>(Endpoints.conversations.show(conversationId))
      .pipe(map((row) => ((row['participants'] ?? []) as Array<Record<string, any>>).map(toParticipant)));
  }

  addParticipants(conversationId: string, userIds: string[]): Observable<void> {
    return this.api
      .post(Endpoints.conversations.addParticipants(conversationId), { userIds })
      .pipe(map(() => undefined));
  }

  addParticipant(conversationId: string, userId: string): Observable<void> {
    return this.addParticipants(conversationId, [userId]);
  }

  removeParticipant(conversationId: string, userId: string): Observable<void> {
    return this.api
      .delete(Endpoints.conversations.removeParticipant(conversationId, userId))
      .pipe(map(() => undefined));
  }

  // ==========================================
  // MESSAGES
  // ==========================================

  getMessages(
    conversationId: string,
    options?: { before?: string; limit?: number },
  ): Observable<Message[]> {
    return this.api
      .getPaged<MessagePayload>(Endpoints.conversations.messages(conversationId), {
        before: options?.before,
        perPage: options?.limit ?? 50,
      })
      .pipe(
        map((page) => page.items.map(toMessage)),
        tap((messages) => this.messagesSubject.next(messages)),
      );
  }

  getMessage(messageId: string, conversationId?: string): Observable<Message> {
    const inConversation = conversationId ?? this.activeConversationSubject.getValue()?.conversation_id ?? '';

    return this.getMessages(inConversation).pipe(
      map((messages) => {
        const found = messages.find((m) => m.message_id === messageId);

        if (found === undefined) {
          throw new Error('That message could not be found.');
        }

        return found;
      }),
    );
  }

  /**
   * Says something.
   *
   * The third argument is the message being replied to. The screen passes
   * a bare id there, so both that and an options object are accepted.
   */
  sendMessage(
    conversationId: string,
    content: string,
    replyToOrOptions?: string | { reply_to_id?: string; message_type?: string },
    _attachments?: unknown,
  ): Observable<Message> {
    const options = typeof replyToOrOptions === 'string'
      ? { reply_to_id: replyToOrOptions }
      : replyToOrOptions;

    return this.api
      .post<MessagePayload>(Endpoints.conversations.send(conversationId), {
        content,
        replyToId: options?.reply_to_id ?? null,
        kind: (options as { message_type?: string })?.message_type ?? 'text',
      })
      .pipe(
        map(toMessage),
        tap((message) => {
          this.messagesSubject.next([...this.messagesSubject.getValue(), message]);
          this.newMessage$.next(message);
        }),
      );
  }

  /**
   * Changes what a message says.
   *
   * The API addresses a message through its conversation, because that is
   * where the permission to touch it lives. The screen only holds the
   * message, so the conversation defaults to the open one — which is the
   * only one whose messages are on screen to be edited.
   */
  editMessage(messageId: string, content: string, conversationId?: string): Observable<Message> {
    const inConversation = conversationId ?? this.activeConversationSubject.getValue()?.conversation_id;

    if (inConversation === undefined || inConversation === '') {
      return throwError(() => new Error('Open the conversation before editing a message.'));
    }

    return this.api
      .put<MessagePayload>(Endpoints.conversations.editMessage(inConversation, messageId), {
        content,
      })
      .pipe(
        map(toMessage),
        tap((message) => this.messageUpdated$.next(message)),
      );
  }

  /**
   * Removes a message.
   *
   * `forEveryone` is accepted and ignored: the API soft-deletes for
   * everybody, because a message half-deleted — gone for the sender,
   * present for the recipient — is a thread that reads differently to two
   * people looking at it together.
   */
  deleteMessage(
    messageId: string,
    _forEveryone = false,
    conversationId?: string,
  ): Observable<void> {
    const inConversation = conversationId ?? this.activeConversationSubject.getValue()?.conversation_id;

    if (inConversation === undefined || inConversation === '') {
      return throwError(() => new Error('Open the conversation before deleting a message.'));
    }

    return this.api
      .delete(Endpoints.conversations.deleteMessage(inConversation, messageId))
      .pipe(
        map(() => undefined),
        tap(() => this.messagesSubject.next(
          this.messagesSubject.getValue().filter((m) => m.message_id !== messageId),
        )),
      );
  }

  // ==========================================
  // PEOPLE
  // ==========================================

  /**
   * People to start a conversation with.
   *
   * `exclude` is applied here rather than sent: it is whoever is already
   * in the conversation, which the screen knows and the server would have
   * to be told anyway.
   */
  searchUsers(search: string, exclude: string[] = []): Observable<Array<Record<string, any>>> {
    const excluded = new Set(exclude.map(String));

    return this.api
      .getPaged<Record<string, any>>(Endpoints.users.list, { search, perPage: 50 })
      .pipe(map((page) => page.items
        .filter((row) => !excluded.has(String(row['id'])))
        .map((row) => ({
          ...row,
          user_id: row['id'],
          first_name: row['firstName'],
          last_name: row['lastName'],
        }))));
  }

  // ==========================================
  // NOT BUILT
  // ==========================================
  //
  // Each of these had a legacy action and none has a table on this side. The
  // schema is conversations, participants and messages.

  addReaction(_messageId: string, _emoji: string): Observable<void> {
    return notAvailable('Reactions');
  }

  removeReaction(_messageId: string, _emoji: string): Observable<void> {
    return notAvailable('Reactions');
  }

  pinMessage(_messageId: string, _pinned = true): Observable<void> {
    return notAvailable('Pinned messages');
  }

  saveMessageBookmark(_messageId: string, _note?: string): Observable<void> {
    return notAvailable('Saved messages');
  }

  unsaveMessage(_messageId: string): Observable<void> {
    return notAvailable('Saved messages');
  }

  getSavedMessages(_limit?: number, _offset?: number): Observable<SavedMessage[]> {
    return notAvailable('Saved messages');
  }

  forwardMessage(_messageId: string, _conversationIds: string[]): Observable<void> {
    return notAvailable('Forwarding a message');
  }

  reportMessage(_messageId: string, _reason: string): Observable<void> {
    return notAvailable('Reporting a message');
  }

  searchMessages(_query: string, _conversationId?: string): Observable<Message[]> {
    return notAvailable('Searching messages');
  }

  getReadReceipts(_messageId: string): Observable<ReadReceipt[]> {
    return notAvailable('Read receipts');
  }

  saveDraft(_conversationId: string, _content: string, _replyToId?: string): Observable<void> {
    return notAvailable('Drafts');
  }

  getDraft(_conversationId: string): Observable<{ content: string } | null> {
    return notAvailable('Drafts');
  }

  deleteDraft(_conversationId: string): Observable<void> {
    return notAvailable('Drafts');
  }

  blockUser(_userId: string, _reason?: string): Observable<void> {
    return notAvailable('Blocking somebody');
  }

  unblockUser(_userId: string): Observable<void> {
    return notAvailable('Blocking somebody');
  }

  getBlockedUsers(): Observable<BlockedUser[]> {
    return notAvailable('Blocking somebody');
  }

  archiveConversation(_conversationId: string): Observable<void> {
    return notAvailable('Archiving a conversation');
  }

  updateParticipantRole(
    _conversationId: string,
    _userId: string,
    _role: string,
  ): Observable<void> {
    return notAvailable('Participant roles');
  }

  createBroadcast(_data: Record<string, unknown>): Observable<Broadcast> {
    return notAvailable('Broadcasts');
  }

  sendBroadcast(_broadcastId: string): Observable<void> {
    return notAvailable('Broadcasts');
  }

  getBroadcast(_broadcastId: string): Observable<Broadcast> {
    return notAvailable('Broadcasts');
  }

  getMyBroadcasts(): Observable<Broadcast[]> {
    return notAvailable('Broadcasts');
  }

  markBroadcastRead(_broadcastId: string): Observable<void> {
    return notAvailable('Broadcasts');
  }

  acknowledgeBroadcast(_broadcastId: string): Observable<void> {
    return notAvailable('Broadcasts');
  }

  getSettings(): Observable<MessagingSettings> {
    return notAvailable('Messaging preferences');
  }

  updateSettings(_settings: Partial<MessagingSettings>): Observable<MessagingSettings> {
    return notAvailable('Messaging preferences');
  }

  /**
   * Typing indicators need a live connection, and there is none.
   *
   * Quiet rather than loud: this fires on every keystroke, and a toast per
   * character typed would make the screen unusable. `typingUsers$` simply
   * never emits anybody.
   */
  setTyping(_conversationId: string, _isTyping: boolean): Observable<void> {
    return of(undefined);
  }

  getTypingUsers(_conversationId: string): Observable<TypingUser[]> {
    return of([]);
  }

  // ==========================================
  // PRESENTATION
  // ==========================================

  formatMessageTime(timestamp: string): string {
    const at = new Date(timestamp);
    const now = new Date();
    const minutes = Math.floor((now.getTime() - at.getTime()) / 60000);

    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;
    if (minutes < 1440) return `${Math.floor(minutes / 60)}h ago`;
    if (minutes < 10080) return `${Math.floor(minutes / 1440)}d ago`;

    return at.toLocaleDateString();
  }

  formatFileSize(bytes: number): string {
    if (bytes === 0) return '0 B';

    const units = ['B', 'KB', 'MB', 'GB'];
    const which = Math.floor(Math.log(bytes) / Math.log(1024));

    return `${(bytes / Math.pow(1024, which)).toFixed(1)} ${units[which]}`;
  }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

interface ConversationPayload extends Record<string, any> {
  id: string;
}

interface MessagePayload extends Record<string, any> {
  id: string;
}

function toConversation(c: ConversationPayload, viewerId: string): Conversation {
  const participants = (c['participants'] ?? []) as Array<Record<string, any>>;
  const other = participants.find((p) => String(p['userId']) !== viewerId);
  const last = c['lastMessage'] as Record<string, any> | null | undefined;

  return {
    conversation_id: c.id,
    tenant_id: '',
    type: (c['kind'] ?? 'direct') as Conversation['type'],
    name: c['title'] ?? null,
    description: c['description'] ?? null,
    avatar_url: null,
    is_private: c['kind'] === 'direct',
    is_readonly: Boolean(c['isReadonly']),
    allow_reactions: false,
    allow_replies: true,
    allow_attachments: false,
    linked_entity_type: null,
    linked_entity_id: null,
    // The last message comes back whole rather than as an id and a
    // preview string, so the list needs no second request to say what
    // was last said.
    last_message_id: last?.['id'] ?? null,
    last_message_at: last?.['createdAt'] ?? null,
    message_count: Number(c['messageCount'] ?? 0),
    created_by: String(c['createdBy'] ?? ''),
    created_at: String(c['createdAt'] ?? ''),
    my_role: (c['myRole'] ?? 'member') as Conversation['my_role'],
    is_muted: c['isMuted'] === true,
    is_pinned: c['isPinned'] === true,
    is_archived: c['isArchived'] === true,
    unread_count: Number(c['unreadCount'] ?? 0),
    last_read_message_id: null,
    notify_all_messages: c['isMuted'] !== true,
    notify_mentions_only: false,
    // The API already names a direct conversation after whoever else is
    // in it — it knows who is asking. The participant is a fallback for
    // a group with no title.
    display_name: c['title'] ?? other?.['name'] ?? 'Conversation',
    display_avatar: other?.['avatarUrl'] ?? null,
    // The names the conversation list binds to. It reads the preview off
    // the conversation rather than the message, so the three fields it
    // wants are filled from the one the API sends.
    last_message_content: last?.['content'] ?? null,
    last_message_sender_id: last?.['senderId'] ?? null,
    sender_first_name: String(last?.['senderName'] ?? '').split(' ')[0] ?? '',
    last_message_preview: last?.['content'] ?? null,
    last_message: last === null || last === undefined ? null : toMessage(last as MessagePayload),
  } as unknown as Conversation;
}

function toParticipant(p: Record<string, any>): Participant {
  // One `name`, not a first and a last: the API composes it, because how
  // a name goes together is not the same everywhere and splitting it to
  // rejoin it in the browser only loses the ones that do not fit.
  const name = String(p['name'] ?? '');
  const [first, ...rest] = name.split(' ');

  return {
    user_id: String(p['userId'] ?? ''),
    first_name: first ?? '',
    last_name: rest.join(' '),
    name,
    email: p['email'] ?? '',
    avatar: p['avatarUrl'] ?? null,
    role: p['role'] ?? 'member',
    is_active: p['isActive'] !== false,
    joined_at: p['joinedAt'] ?? null,
  } as unknown as Participant;
}

function toMessage(m: MessagePayload): Message {
  return {
    message_id: m.id,
    conversation_id: String(m['conversationId'] ?? ''),
    sender_id: String(m['senderId'] ?? ''),
    tenant_id: '',
    content: String(m['content'] ?? ''),
    content_html: String(m['content'] ?? ''),
    content_plain: String(m['content'] ?? ''),
    message_type: (m['kind'] ?? 'text') as Message['message_type'],
    reply_to_id: m['replyToId'] ?? null,
    thread_root_id: null,
    thread_reply_count: 0,
    thread_last_reply_at: null,
    forwarded_from_id: null,
    is_edited: m['isEdited'] === true,
    edited_at: m['editedAt'] ?? null,
    is_deleted: m['isDeleted'] === true,
    is_pinned: false,
    pinned_at: null,
    priority: 'normal',
    metadata: null,
    created_at: String(m['createdAt'] ?? ''),
    sender_name: String(m['senderName'] ?? ''),
    sender_first_name: String(m['senderName'] ?? '').split(' ')[0] ?? '',
    sender_last_name: String(m['senderName'] ?? '').split(' ').slice(1).join(' '),
    sender_avatar: m['senderAvatarUrl'] ?? null,
    attachments: [],
    reactions: [],
  } as unknown as Message;
}

/** A feature with no server behind it, said out loud rather than left empty. */
function notAvailable(what: string): Observable<never> {
  return throwError(() => new Error(`${what} are not available yet.`));
}
