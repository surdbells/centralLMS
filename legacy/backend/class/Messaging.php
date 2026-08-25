<?php

/**
 * Messaging Class
 *
 * Handles all internal messaging operations including:
 * - Direct messages (1:1)
 * - Group conversations
 * - Channels
 * - Broadcast/announcements
 * - Reactions, mentions, attachments
 * - Read receipts and typing indicators
 *
 * @package LMS
 * @version 1.0.0
 */

class Messaging
{
    private $conn;
    private int $tenant_id;
    private int $user_id;

    public function __construct($db_connection, int $tenant_id, int $user_id)
    {
        $this->conn = $db_connection;
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
    }

    /**
     * Helper method to bind parameters dynamically
     * Handles the reference issue with bind_param
     */
    private function bindParams($stmt, $types, $params)
    {
        $refs = [];
        $refs[] = $types;

        foreach ($params as $key => $value) {
            $refs[] = &$params[$key];
        }

        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    // ==========================================
    // CONVERSATION MANAGEMENT
    // ==========================================

    /**
     * Create a new conversation
     * @throws Exception
     */
    public function createConversation(array $data): array
    {
        $type = $data['type'] ?? 'direct';

        // For direct messages, check if conversation already exists
        if ($type === 'direct' && !empty($data['participants'])) {
            $existing = $this->findDirectConversation($data['participants'][0]);
            if ($existing) {
                return $existing;
            }
        }

        $sql = "INSERT INTO conversations (
            tenant_id, type, name, description, avatar_url,
            is_private, is_readonly, allow_reactions, allow_replies, allow_attachments,
            linked_entity_type, linked_entity_id, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        // Assign values to variables for bind_param (requires references)
        $name = $data['name'] ?? null;
        $description = $data['description'] ?? null;
        $avatar_url = $data['avatar_url'] ?? null;
        $is_private = $data['is_private'] ?? 1;
        $is_readonly = $data['is_readonly'] ?? 0;
        $allow_reactions = $data['allow_reactions'] ?? 1;
        $allow_replies = $data['allow_replies'] ?? 1;
        $allow_attachments = $data['allow_attachments'] ?? 1;
        $linked_entity_type = $data['linked_entity_type'] ?? null;
        $linked_entity_id = $data['linked_entity_id'] ?? null;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "issssiiiiisii",
            $this->tenant_id,
            $type,
            $name,
            $description,
            $avatar_url,
            $is_private,
            $is_readonly,
            $allow_reactions,
            $allow_replies,
            $allow_attachments,
            $linked_entity_type,
            $linked_entity_id,
            $this->user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create conversation: " . $stmt->error);
        }

        $conversation_id = $this->conn->insert_id;
        $stmt->close();

        // Add creator as owner
        $this->addParticipant($conversation_id, $this->user_id, 'owner');

        // Add other participants
        if (!empty($data['participants'])) {
            foreach ($data['participants'] as $participant_id) {
                if ($participant_id != $this->user_id) {
                    $this->addParticipant($conversation_id, (int) $participant_id, 'member');
                }
            }
        }

        return $this->getConversation($conversation_id);
    }

    /**
     * Find existing direct conversation between two users
     */
    public function findDirectConversation(int $other_user_id): ?array
    {
        $sql = "
            SELECT c.conversation_id
            FROM conversations c
            JOIN conversation_participants cp1 ON c.conversation_id = cp1.conversation_id AND cp1.user_id = ?
            JOIN conversation_participants cp2 ON c.conversation_id = cp2.conversation_id AND cp2.user_id = ?
            WHERE c.type = 'direct' AND c.tenant_id = ?
            AND cp1.left_at IS NULL AND cp2.left_at IS NULL
            LIMIT 1
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $this->user_id, $other_user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result) {
            return $this->getConversation($result['conversation_id']);
        }

        return null;
    }

    /**
     * Get conversation details
     */
    public function getConversation(int $conversation_id): ?array
    {
        $sql = "
            SELECT 
                c.*,
                cp.role as my_role,
                cp.is_muted,
                cp.is_pinned,
                cp.is_archived,
                cp.unread_count,
                cp.last_read_message_id,
                cp.notify_all_messages,
                cp.notify_mentions_only
            FROM conversations c
            JOIN conversation_participants cp ON c.conversation_id = cp.conversation_id AND cp.user_id = ?
            WHERE c.conversation_id = ? AND c.tenant_id = ?
            AND cp.left_at IS NULL
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $this->user_id, $conversation_id, $this->tenant_id);
        $stmt->execute();
        $conversation = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($conversation) {
            $conversation['participants'] = $this->getParticipants($conversation_id);

            // For direct chats, set the other user's info as the conversation name
            if ($conversation['type'] === 'direct') {
                foreach ($conversation['participants'] as $p) {
                    if ($p['user_id'] != $this->user_id) {
                        $conversation['display_name'] = $p['first_name'] . ' ' . $p['last_name'];
                        $conversation['display_avatar'] = $p['profile_image'];
                        $conversation['other_user'] = $p;
                        break;
                    }
                }
            }
        }

        return $conversation;
    }

    /**
     * Get user's conversations
     */
    public function getConversations(array $filters = []): array
    {
        $sql = "
            SELECT 
                c.*,
                cp.role as my_role,
                cp.is_muted,
                cp.is_pinned,
                cp.is_archived,
                cp.unread_count,
                m.content as last_message_content,
                m.message_type as last_message_type,
                m.sender_id as last_message_sender_id,
                sender.first_name as sender_first_name,
                sender.last_name as sender_last_name
            FROM conversations c
            JOIN conversation_participants cp ON c.conversation_id = cp.conversation_id AND cp.user_id = ?
            LEFT JOIN messages m ON c.last_message_id = m.message_id
            LEFT JOIN users sender ON m.sender_id = sender.id
            WHERE c.tenant_id = ? AND cp.left_at IS NULL
        ";

        $params = [$this->user_id, $this->tenant_id];
        $types = "ii";

        if (!empty($filters['type'])) {
            $sql .= " AND c.type = ?";
            $params[] = $filters['type'];
            $types .= "s";
        }

        if (isset($filters['is_archived'])) {
            $sql .= " AND cp.is_archived = ?";
            $params[] = $filters['is_archived'];
            $types .= "i";
        } else {
            $sql .= " AND cp.is_archived = 0";
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (c.name LIKE ? OR EXISTS (
                SELECT 1 FROM conversation_participants cp2 
                JOIN users u ON cp2.user_id = u.id 
                WHERE cp2.conversation_id = c.conversation_id 
                AND CONCAT(u.first_name, ' ', u.last_name) LIKE ?
            ))";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
            $types .= "ss";
        }

        // Order: pinned first, then by last message
        $sql .= " ORDER BY cp.is_pinned DESC, c.last_message_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = (int) $filters['limit'];
            $types .= "i";

            if (!empty($filters['offset'])) {
                $sql .= " OFFSET ?";
                $params[] = (int) $filters['offset'];
                $types .= "i";
            }
        }

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $conversations = [];
        while ($row = $result->fetch_assoc()) {
            // Get participants for direct chats
            if ($row['type'] === 'direct') {
                $participants = $this->getParticipants($row['conversation_id']);
                foreach ($participants as $p) {
                    if ($p['user_id'] != $this->user_id) {
                        $row['display_name'] = $p['first_name'] . ' ' . $p['last_name'];
                        $row['display_avatar'] = $p['profile_image'];
                        $row['other_user_id'] = $p['user_id'];
                        $row['online_status'] = $p['online_status'] ?? null;
                        break;
                    }
                }
            } else {
                $row['display_name'] = $row['name'];
                $row['display_avatar'] = $row['avatar_url'];
            }

            $conversations[] = $row;
        }
        $stmt->close();

        return $conversations;
    }

    /**
     * Update conversation settings
     */
    public function updateConversation(int $conversation_id, array $data): array
    {
        // Check permissions
        $this->checkConversationPermission($conversation_id, 'edit_settings');

        $fields = [];
        $params = [];
        $types = "";

        $allowedFields = [
            'name' => 's', 'description' => 's', 'avatar_url' => 's',
            'is_private' => 'i', 'is_readonly' => 'i',
            'allow_reactions' => 'i', 'allow_replies' => 'i', 'allow_attachments' => 'i'
        ];

        foreach ($allowedFields as $field => $type) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }

        if (empty($fields)) {
            return $this->getConversation($conversation_id);
        }

        $params[] = $conversation_id;
        $params[] = $this->tenant_id;
        $types .= "ii";

        $sql = "UPDATE conversations SET " . implode(", ", $fields) .
            " WHERE conversation_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update conversation: " . $stmt->error);
        }
        $stmt->close();

        return $this->getConversation($conversation_id);
    }

    /**
     * Archive/Unarchive conversation (for user)
     */
    public function archiveConversation(int $conversation_id, bool $archive = true): bool
    {
        $sql = "UPDATE conversation_participants SET is_archived = ? 
                WHERE conversation_id = ? AND user_id = ?";

        $stmt = $this->conn->prepare($sql);
        $archived = $archive ? 1 : 0;
        $stmt->bind_param("iii", $archived, $conversation_id, $this->user_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Leave conversation
     */
    public function leaveConversation(int $conversation_id): bool
    {
        $sql = "UPDATE conversation_participants SET left_at = NOW() 
                WHERE conversation_id = ? AND user_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $conversation_id, $this->user_id);
        $result = $stmt->execute();
        $stmt->close();

        // Send system message
        $this->sendSystemMessage($conversation_id, 'left the conversation');

        return $result;
    }

    // ==========================================
    // PARTICIPANT MANAGEMENT
    // ==========================================

    /**
     * Add participant to conversation
     */
    public function addParticipant(int $conversation_id, int $user_id, string $role = 'member'): bool
    {
        // Check if user is blocked
        if ($this->isBlocked($user_id) || $this->isBlockedBy($user_id)) {
            throw new Exception("Cannot add this user to the conversation");
        }

        $sql = "INSERT INTO conversation_participants (
            conversation_id, user_id, tenant_id, role, joined_at
        ) VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE left_at = NULL, role = VALUES(role)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiis", $conversation_id, $user_id, $this->tenant_id, $role);
        $result = $stmt->execute();
        $stmt->close();

        if ($result && $user_id != $this->user_id) {
            // Queue notification
            $this->queueNotification('added_to_group', $user_id, null, $conversation_id);
        }

        return $result;
    }

    /**
     * Remove participant from conversation
     */
    public function removeParticipant(int $conversation_id, int $user_id): bool
    {
        $this->checkConversationPermission($conversation_id, 'remove_members');

        $sql = "UPDATE conversation_participants SET left_at = NOW() 
                WHERE conversation_id = ? AND user_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $conversation_id, $user_id);
        $result = $stmt->execute();
        $stmt->close();

        // Send system message
        $this->sendSystemMessage($conversation_id, 'was removed from the conversation', $user_id);

        return $result;
    }

    /**
     * Get conversation participants
     * @throws Exception
     */
    public function getParticipants(int $conversation_id): array
    {
        $sql = "
            SELECT 
                cp.*,
                u.first_name,
                u.last_name,
                u.email,
                u.avatar,
                u.last_login_at
            FROM conversation_participants cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.conversation_id = ? AND cp.left_at IS NULL
            ORDER BY cp.role ASC, u.first_name ASC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $conversation_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $participants = [];
        while ($row = $result->fetch_assoc()) {
            // Determine online status
            if ($row['last_login_at']) {
                $lastActive = new DateTime($row['last_login_at']);
                $now = new DateTime();
                $diff = $now->getTimestamp() - $lastActive->getTimestamp();
                $row['online_status'] = $diff < 300 ? 'online' : ($diff < 900 ? 'away' : 'offline');
            } else {
                $row['online_status'] = 'offline';
            }
            $participants[] = $row;
        }
        $stmt->close();

        return $participants;
    }

    /**
     * Update participant role
     */
    public function updateParticipantRole(int $conversation_id, int $user_id, string $role): bool
    {
        $this->checkConversationPermission($conversation_id, 'edit_settings');

        $sql = "UPDATE conversation_participants SET role = ? 
                WHERE conversation_id = ? AND user_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sii", $role, $conversation_id, $user_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    // ==========================================
    // MESSAGING
    // ==========================================

    /**
     * Send a message
     */
    public function sendMessage(int $conversation_id, array $data): array
    {
        // Verify participant and can post
        $participant = $this->getParticipantInfo($conversation_id);
        if (!$participant) {
            throw new Exception("You are not a participant in this conversation");
        }

        if (!$participant['can_post']) {
            throw new Exception("You don't have permission to post in this conversation");
        }

        // Check if conversation is readonly
        $conversation = $this->getConversation($conversation_id);
        if ($conversation['is_readonly'] && $participant['role'] === 'member') {
            throw new Exception("This conversation is read-only");
        }

        // Process content
        $content = $data['content'] ?? '';
        $content_html = $this->processMessageContent($content);
        $content_plain = strip_tags($content);

        // Extract mentions
        $mentions = $this->extractMentions($content);

        $sql = "INSERT INTO messages (
            conversation_id, sender_id, tenant_id, content, content_html, content_plain,
            message_type, reply_to_id, thread_root_id, forwarded_from_id, priority, metadata
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $message_type = $data['message_type'] ?? 'text';
        $reply_to_id = $data['reply_to_id'] ?? null;
        $thread_root_id = $data['thread_root_id'] ?? null;
        $forwarded_from_id = $data['forwarded_from_id'] ?? null;
        $priority = $data['priority'] ?? 'normal';
        $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "iiissssiiiss",
            $conversation_id,
            $this->user_id,
            $this->tenant_id,
            $content,
            $content_html,
            $content_plain,
            $message_type,
            $reply_to_id,
            $thread_root_id,
            $forwarded_from_id,
            $priority,
            $metadata
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to send message: " . $stmt->error);
        }

        $message_id = $this->conn->insert_id;
        $stmt->close();

        // Handle attachments
        if (!empty($data['attachments'])) {
            foreach ($data['attachments'] as $attachment) {
                $this->addAttachment($message_id, $attachment);
            }
        }

        // Store mentions
        $this->storeMentions($message_id, $mentions);

        // Update conversation
        $this->updateConversationLastMessage($conversation_id, $message_id);

        // Update thread if reply
        if ($thread_root_id) {
            $this->updateThreadStats($thread_root_id);
        }

        // Update unread counts for other participants
        $this->incrementUnreadCounts($conversation_id, $message_id);

        // Queue notifications
        $this->queueMessageNotifications($conversation_id, $message_id, $mentions);

        // Clear draft
        $this->deleteDraft($conversation_id);

        return $this->getMessage($message_id);
    }

    /**
     * Get a single message
     */
    public function getMessage(int $message_id): ?array
    {
        $sql = "
            SELECT 
                m.*,
                u.first_name as sender_first_name,
                u.last_name as sender_last_name,
                u.avatar as sender_avatar
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.message_id = ? AND m.tenant_id = ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $message_id, $this->tenant_id);
        $stmt->execute();
        $message = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($message && !$message['is_deleted']) {
            $message['attachments'] = $this->getMessageAttachments($message_id);
            $message['reactions'] = $this->getMessageReactions($message_id);
            $message['metadata'] = $message['metadata'] ? json_decode($message['metadata'], true) : null;

            // Get reply info if exists
            if ($message['reply_to_id']) {
                $message['reply_to'] = $this->getMessagePreview($message['reply_to_id']);
            }
        }

        return $message;
    }

    /**
     * Get messages in a conversation
     */
    public function getMessages(int $conversation_id, array $options = []): array
    {
        // Verify participant
        if (!$this->isParticipant($conversation_id)) {
            throw new Exception("You are not a participant in this conversation");
        }

        $sql = "
            SELECT 
                m.*,
                u.first_name as sender_first_name,
                u.last_name as sender_last_name,
                u.avatar as sender_avatar
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ? AND m.is_deleted = 0
        ";

        $params = [$conversation_id];
        $types = "i";

        // For pagination
        if (!empty($options['before_id'])) {
            $sql .= " AND m.message_id < ?";
            $params[] = $options['before_id'];
            $types .= "i";
        }

        if (!empty($options['after_id'])) {
            $sql .= " AND m.message_id > ?";
            $params[] = $options['after_id'];
            $types .= "i";
        }

        // Thread filter
        if (!empty($options['thread_root_id'])) {
            $sql .= " AND (m.thread_root_id = ? OR m.message_id = ?)";
            $params[] = $options['thread_root_id'];
            $params[] = $options['thread_root_id'];
            $types .= "ii";
        } elseif (empty($options['include_thread_replies'])) {
            $sql .= " AND m.thread_root_id IS NULL";
        }

        // Pinned only
        if (!empty($options['pinned_only'])) {
            $sql .= " AND m.is_pinned = 1";
        }

        $sql .= " ORDER BY m.created_at " . (!empty($options['after_id']) ? "ASC" : "DESC");

        $limit = min($options['limit'] ?? 50, 100);
        $sql .= " LIMIT ?";
        $params[] = $limit;
        $types .= "i";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $row['attachments'] = $this->getMessageAttachments($row['message_id']);
            $row['reactions'] = $this->getMessageReactions($row['message_id']);
            $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : null;

            if ($row['reply_to_id']) {
                $row['reply_to'] = $this->getMessagePreview($row['reply_to_id']);
            }

            $messages[] = $row;
        }
        $stmt->close();

        // Reverse if fetching newer messages
        if (empty($options['after_id'])) {
            $messages = array_reverse($messages);
        }

        return $messages;
    }

    /**
     * Edit a message
     */
    public function editMessage(int $message_id, string $content): array
    {
        // Verify ownership
        $message = $this->getMessage($message_id);
        if (!$message || $message['sender_id'] != $this->user_id) {
            throw new Exception("You can only edit your own messages");
        }

        // Check time limit (e.g., 15 minutes)
        $created = new DateTime($message['created_at']);
        $now = new DateTime();
        if (($now->getTimestamp() - $created->getTimestamp()) > 900) {
            throw new Exception("Messages can only be edited within 15 minutes");
        }

        $content_html = $this->processMessageContent($content);
        $content_plain = strip_tags($content);

        $sql = "UPDATE messages SET 
                content = ?, content_html = ?, content_plain = ?,
                is_edited = 1, edited_at = NOW()
                WHERE message_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("sssi", $content, $content_html, $content_plain, $message_id);

        if (!$stmt->execute()) {
            throw new Exception("Failed to edit message");
        }
        $stmt->close();

        return $this->getMessage($message_id);
    }

    /**
     * Delete a message
     */
    public function deleteMessage(int $message_id, bool $forEveryone = false): bool
    {
        $message = $this->getMessage($message_id);
        if (!$message) {
            throw new Exception("Message not found");
        }

        // Check permission
        $canDelete = $message['sender_id'] == $this->user_id;
        if (!$canDelete && $forEveryone) {
            $participant = $this->getParticipantInfo($message['conversation_id']);
            $canDelete = $participant && $participant['can_delete_messages'];
        }

        if (!$canDelete) {
            throw new Exception("You don't have permission to delete this message");
        }

        $sql = "UPDATE messages SET 
                is_deleted = 1, deleted_at = NOW(), deleted_by = ?,
                content = '[Message deleted]', content_html = '[Message deleted]', content_plain = ''
                WHERE message_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->user_id, $message_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Pin/Unpin a message
     */
    public function pinMessage(int $message_id, bool $pin = true): bool
    {
        $message = $this->getMessage($message_id);
        if (!$message) {
            throw new Exception("Message not found");
        }

        // Check permission
        $participant = $this->getParticipantInfo($message['conversation_id']);
        if (!$participant || !$participant['can_pin_messages']) {
            throw new Exception("You don't have permission to pin messages");
        }

        $sql = "UPDATE messages SET 
                is_pinned = ?, pinned_at = ?, pinned_by = ?
                WHERE message_id = ?";

        $pinned = $pin ? 1 : 0;
        $pinned_at = $pin ? date('Y-m-d H:i:s') : null;
        $pinned_by = $pin ? $this->user_id : null;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isii", $pinned, $pinned_at, $pinned_by, $message_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Forward a message
     */
    public function forwardMessage(int $message_id, array $conversation_ids): array
    {
        $original = $this->getMessage($message_id);
        if (!$original) {
            throw new Exception("Original message not found");
        }

        $forwarded = [];
        foreach ($conversation_ids as $conv_id) {
            try {
                $new_message = $this->sendMessage($conv_id, [
                    'content' => $original['content'],
                    'message_type' => $original['message_type'],
                    'forwarded_from_id' => $message_id,
                    'attachments' => $original['attachments'] ?? []
                ]);
                $forwarded[] = $new_message;
            } catch (Exception $e) {
                // Skip conversations where user can't send
            }
        }

        return $forwarded;
    }

    // ==========================================
    // REACTIONS
    // ==========================================

    /**
     * Add reaction to message
     */
    public function addReaction(int $message_id, string $emoji): bool
    {
        $message = $this->getMessage($message_id);
        if (!$message) {
            throw new Exception("Message not found");
        }

        // Check if reactions are allowed
        $conversation = $this->getConversation($message['conversation_id']);
        if (!$conversation['allow_reactions']) {
            throw new Exception("Reactions are not allowed in this conversation");
        }

        $sql = "INSERT INTO message_reactions (message_id, user_id, tenant_id, emoji)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE emoji = VALUES(emoji)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiis", $message_id, $this->user_id, $this->tenant_id, $emoji);
        $result = $stmt->execute();
        $stmt->close();

        // Notify message sender
        if ($message['sender_id'] != $this->user_id) {
            $this->queueNotification('reaction', $message['sender_id'], $message_id, $message['conversation_id']);
        }

        return $result;
    }

    /**
     * Remove reaction
     */
    public function removeReaction(int $message_id, string $emoji): bool
    {
        $sql = "DELETE FROM message_reactions 
                WHERE message_id = ? AND user_id = ? AND emoji = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iis", $message_id, $this->user_id, $emoji);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get message reactions
     */
    private function getMessageReactions(int $message_id): array
    {
        $sql = "
            SELECT 
                emoji,
                COUNT(*) as count,
                GROUP_CONCAT(user_id) as user_ids,
                MAX(CASE WHEN user_id = ? THEN 1 ELSE 0 END) as reacted_by_me
            FROM message_reactions
            WHERE message_id = ?
            GROUP BY emoji
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->user_id, $message_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $reactions = [];
        while ($row = $result->fetch_assoc()) {
            $row['user_ids'] = explode(',', $row['user_ids']);
            $row['reacted_by_me'] = (bool) $row['reacted_by_me'];
            $reactions[] = $row;
        }
        $stmt->close();

        return $reactions;
    }

    // ==========================================
    // READ RECEIPTS & TYPING
    // ==========================================

    /**
     * Mark messages as read
     */
    public function markAsRead(int $conversation_id, ?int $up_to_message_id = null): bool
    {
        // Get latest message if not specified
        if (!$up_to_message_id) {
            $stmt = $this->conn->prepare(
                "SELECT MAX(message_id) as max_id FROM messages WHERE conversation_id = ?"
            );
            $stmt->bind_param("i", $conversation_id);
            $stmt->execute();
            $up_to_message_id = $stmt->get_result()->fetch_assoc()['max_id'];
            $stmt->close();
        }

        if (!$up_to_message_id) {
            return true;
        }

        // Update participant read status
        $sql = "UPDATE conversation_participants SET 
                last_read_message_id = ?,
                last_read_at = NOW(),
                unread_count = 0
                WHERE conversation_id = ? AND user_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $up_to_message_id, $conversation_id, $this->user_id);
        $stmt->execute();
        $stmt->close();

        // Create read receipts for unread messages
        $sql = "INSERT IGNORE INTO message_read_receipts (message_id, user_id, tenant_id)
                SELECT message_id, ?, ?
                FROM messages 
                WHERE conversation_id = ? 
                AND message_id <= ?
                AND sender_id != ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiii", $this->user_id, $this->tenant_id, $conversation_id, $up_to_message_id, $this->user_id);
        $stmt->execute();
        $stmt->close();

        // Mark mentions as read
        $sql = "UPDATE message_mentions mm
                JOIN messages m ON mm.message_id = m.message_id
                SET mm.is_read = 1, mm.read_at = NOW()
                WHERE m.conversation_id = ? 
                AND mm.mentioned_user_id = ?
                AND mm.is_read = 0";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $conversation_id, $this->user_id);
        $stmt->execute();
        $stmt->close();

        return true;
    }

    /**
     * Get read receipts for a message
     */
    public function getReadReceipts(int $message_id): array
    {
        $sql = "
            SELECT 
                rr.user_id,
                rr.read_at,
                u.first_name,
                u.last_name,
                u.avatar
            FROM message_read_receipts rr
            JOIN users u ON rr.user_id = u.id
            WHERE rr.message_id = ?
            ORDER BY rr.read_at DESC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $message_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $receipts = [];
        while ($row = $result->fetch_assoc()) {
            $receipts[] = $row;
        }
        $stmt->close();

        return $receipts;
    }

    /**
     * Set typing indicator
     */
    public function setTyping(int $conversation_id, bool $typing = true): bool
    {
        if ($typing) {
            $expires_at = date('Y-m-d H:i:s', time() + 5);
            $sql = "INSERT INTO typing_indicators (conversation_id, user_id, tenant_id, expires_at)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE started_at = NOW(), expires_at = VALUES(expires_at)";

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("iiis", $conversation_id, $this->user_id, $this->tenant_id, $expires_at);
        } else {
            $sql = "DELETE FROM typing_indicators WHERE conversation_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $conversation_id, $this->user_id);
        }

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get typing users in conversation
     */
    public function getTypingUsers(int $conversation_id): array
    {
        $sql = "
            SELECT 
                ti.user_id,
                u.first_name,
                u.last_name
            FROM typing_indicators ti
            JOIN users u ON ti.user_id = u.id
            WHERE ti.conversation_id = ? 
            AND ti.user_id != ?
            AND ti.expires_at > NOW()
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $conversation_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();

        return $users;
    }

    // ==========================================
    // SEARCH
    // ==========================================

    /**
     * Search messages
     */
    public function searchMessages(string $query, array $options = []): array
    {
        $sql = "
            SELECT 
                m.*,
                c.name as conversation_name,
                c.type as conversation_type,
                u.first_name as sender_first_name,
                u.last_name as sender_last_name,
                MATCH(m.content_plain) AGAINST(? IN NATURAL LANGUAGE MODE) as relevance
            FROM messages m
            JOIN conversations c ON m.conversation_id = c.conversation_id
            JOIN conversation_participants cp ON c.conversation_id = cp.conversation_id AND cp.user_id = ?
            JOIN users u ON m.sender_id = u.id
            WHERE m.tenant_id = ?
            AND m.is_deleted = 0
            AND cp.left_at IS NULL
            AND MATCH(m.content_plain) AGAINST(? IN NATURAL LANGUAGE MODE)
        ";

        $params = [$query, $this->user_id, $this->tenant_id, $query];
        $types = "siis";

        if (!empty($options['conversation_id'])) {
            $sql .= " AND m.conversation_id = ?";
            $params[] = $options['conversation_id'];
            $types .= "i";
        }

        if (!empty($options['sender_id'])) {
            $sql .= " AND m.sender_id = ?";
            $params[] = $options['sender_id'];
            $types .= "i";
        }

        if (!empty($options['from_date'])) {
            $sql .= " AND m.created_at >= ?";
            $params[] = $options['from_date'];
            $types .= "s";
        }

        if (!empty($options['to_date'])) {
            $sql .= " AND m.created_at <= ?";
            $params[] = $options['to_date'];
            $types .= "s";
        }

        if (!empty($options['has_attachments'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM message_attachments WHERE message_id = m.message_id)";
        }

        $sql .= " ORDER BY relevance DESC, m.created_at DESC";

        $limit = min($options['limit'] ?? 50, 100);
        $sql .= " LIMIT ?";
        $params[] = $limit;
        $types .= "i";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $messages = [];
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt->close();

        return $messages;
    }

    // ==========================================
    // ATTACHMENTS
    // ==========================================

    /**
     * Add attachment to message
     */
    private function addAttachment(int $message_id, array $attachment): int
    {
        $sql = "INSERT INTO message_attachments (
            message_id, tenant_id, file_name, file_type, file_size, file_url, file_path,
            thumbnail_url, width, height, duration, metadata
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $metadata = isset($attachment['metadata']) ? json_encode($attachment['metadata']) : null;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "iissisisiiis",
            $message_id,
            $this->tenant_id,
            $attachment['file_name'],
            $attachment['file_type'],
            $attachment['file_size'],
            $attachment['file_url'],
            $attachment['file_path'] ?? null,
            $attachment['thumbnail_url'] ?? null,
            $attachment['width'] ?? null,
            $attachment['height'] ?? null,
            $attachment['duration'] ?? null,
            $metadata
        );

        $stmt->execute();
        $attachment_id = $this->conn->insert_id;
        $stmt->close();

        return $attachment_id;
    }

    /**
     * Get message attachments
     */
    private function getMessageAttachments(int $message_id): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM message_attachments WHERE message_id = ?");
        $stmt->bind_param("i", $message_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $attachments = [];
        while ($row = $result->fetch_assoc()) {
            $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : null;
            $attachments[] = $row;
        }
        $stmt->close();

        return $attachments;
    }

    // ==========================================
    // DRAFTS
    // ==========================================

    /**
     * Save draft
     */
    public function saveDraft(int $conversation_id, string $content, ?int $reply_to_id = null): bool
    {
        $sql = "INSERT INTO message_drafts (conversation_id, user_id, tenant_id, content, reply_to_id)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE content = VALUES(content), reply_to_id = VALUES(reply_to_id), updated_at = NOW()";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiisi", $conversation_id, $this->user_id, $this->tenant_id, $content, $reply_to_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get draft
     */
    public function getDraft(int $conversation_id): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM message_drafts WHERE conversation_id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $conversation_id, $this->user_id);
        $stmt->execute();
        $draft = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $draft;
    }

    /**
     * Delete draft
     */
    public function deleteDraft(int $conversation_id): bool
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM message_drafts WHERE conversation_id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $conversation_id, $this->user_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    // ==========================================
    // SAVED MESSAGES
    // ==========================================

    /**
     * Save/bookmark a message
     */
    public function saveMessage(int $message_id, ?string $note = null): bool
    {
        $sql = "INSERT INTO saved_messages (message_id, user_id, tenant_id, note)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE note = VALUES(note)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiis", $message_id, $this->user_id, $this->tenant_id, $note);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Unsave a message
     */
    public function unsaveMessage(int $message_id): bool
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM saved_messages WHERE message_id = ? AND user_id = ?"
        );
        $stmt->bind_param("ii", $message_id, $this->user_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get saved messages
     */
    public function getSavedMessages(int $limit = 50, int $offset = 0): array
    {
        $sql = "
            SELECT 
                sm.*,
                m.content,
                m.created_at as message_created_at,
                m.conversation_id,
                c.name as conversation_name,
                u.first_name as sender_first_name,
                u.last_name as sender_last_name
            FROM saved_messages sm
            JOIN messages m ON sm.message_id = m.message_id
            JOIN conversations c ON m.conversation_id = c.conversation_id
            JOIN users u ON m.sender_id = u.id
            WHERE sm.user_id = ? AND sm.tenant_id = ?
            ORDER BY sm.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiii", $this->user_id, $this->tenant_id, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $saved = [];
        while ($row = $result->fetch_assoc()) {
            $saved[] = $row;
        }
        $stmt->close();

        return $saved;
    }

    // ==========================================
    // BROADCAST MESSAGES
    // ==========================================

    /**
     * Create broadcast message
     */
    public function createBroadcast(array $data): array
    {
        $sql = "INSERT INTO broadcast_messages (
            tenant_id, title, content, content_html, target_type, target_criteria,
            priority, requires_acknowledgment, scheduled_at, expires_at, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $content_html = $this->processMessageContent($data['content']);
        $target_criteria = isset($data['target_criteria']) ? json_encode($data['target_criteria']) : null;
        $status = !empty($data['scheduled_at']) ? 'scheduled' : 'draft';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "issssssisssi",
            $this->tenant_id,
            $data['title'],
            $data['content'],
            $content_html,
            $data['target_type'] ?? 'all',
            $target_criteria,
            $data['priority'] ?? 'normal',
            $data['requires_acknowledgment'] ?? 0,
            $data['scheduled_at'] ?? null,
            $data['expires_at'] ?? null,
            $status,
            $this->user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create broadcast: " . $stmt->error);
        }

        $broadcast_id = $this->conn->insert_id;
        $stmt->close();

        return $this->getBroadcast($broadcast_id);
    }

    /**
     * Send broadcast immediately
     */
    public function sendBroadcast(int $broadcast_id): array
    {
        $broadcast = $this->getBroadcast($broadcast_id);
        if (!$broadcast) {
            throw new Exception("Broadcast not found");
        }

        // Get target recipients
        $recipients = $this->getBroadcastRecipients($broadcast);

        // Create recipient records
        $sql = "INSERT INTO broadcast_recipients (broadcast_id, user_id, tenant_id) VALUES (?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        foreach ($recipients as $user_id) {
            $stmt->bind_param("iii", $broadcast_id, $user_id, $this->tenant_id);
            $stmt->execute();
        }
        $stmt->close();

        // Update broadcast status
        $count = count($recipients);
        $sql = "UPDATE broadcast_messages SET 
                status = 'sent', sent_at = NOW(), recipients_count = ?
                WHERE broadcast_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $count, $broadcast_id);
        $stmt->execute();
        $stmt->close();

        // Queue notifications
        foreach ($recipients as $user_id) {
            $this->queueNotification('broadcast', $user_id, null, null, $broadcast_id);
        }

        return $this->getBroadcast($broadcast_id);
    }

    /**
     * Get broadcast
     */
    public function getBroadcast(int $broadcast_id): ?array
    {
        $sql = "SELECT * FROM broadcast_messages WHERE broadcast_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $broadcast_id, $this->tenant_id);
        $stmt->execute();
        $broadcast = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($broadcast) {
            $broadcast['target_criteria'] = $broadcast['target_criteria']
                ? json_decode($broadcast['target_criteria'], true) : null;
        }

        return $broadcast;
    }

    /**
     * Get user's broadcasts
     */
    public function getMyBroadcasts(bool $unread_only = false): array
    {
        $sql = "
            SELECT 
                bm.*,
                br.is_read,
                br.is_acknowledged,
                br.read_at,
                u.first_name as sender_first_name,
                u.last_name as sender_last_name
            FROM broadcast_recipients br
            JOIN broadcast_messages bm ON br.broadcast_id = bm.broadcast_id
            JOIN users u ON bm.created_by = u.id
            WHERE br.user_id = ? AND br.tenant_id = ?
            AND bm.status = 'sent'
            AND (bm.expires_at IS NULL OR bm.expires_at > NOW())
        ";

        if ($unread_only) {
            $sql .= " AND br.is_read = 0";
        }

        $sql .= " ORDER BY bm.sent_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $broadcasts = [];
        while ($row = $result->fetch_assoc()) {
            $broadcasts[] = $row;
        }
        $stmt->close();

        return $broadcasts;
    }

    /**
     * Mark broadcast as read
     */
    public function markBroadcastRead(int $broadcast_id): bool
    {
        $sql = "UPDATE broadcast_recipients SET is_read = 1, read_at = NOW()
                WHERE broadcast_id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $broadcast_id, $this->user_id);
        $result = $stmt->execute();
        $stmt->close();

        // Update read count
        $this->conn->query("UPDATE broadcast_messages SET read_count = read_count + 1 WHERE broadcast_id = $broadcast_id");

        return $result;
    }

    /**
     * Acknowledge broadcast
     */
    public function acknowledgeBroadcast(int $broadcast_id): bool
    {
        $sql = "UPDATE broadcast_recipients SET 
                is_acknowledged = 1, acknowledged_at = NOW(),
                is_read = 1, read_at = COALESCE(read_at, NOW())
                WHERE broadcast_id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $broadcast_id, $this->user_id);
        $result = $stmt->execute();
        $stmt->close();

        // Update counts
        $this->conn->query("UPDATE broadcast_messages SET 
            acknowledged_count = acknowledged_count + 1,
            read_count = read_count + 1 
            WHERE broadcast_id = $broadcast_id");

        return $result;
    }

    // ==========================================
    // BLOCKING
    // ==========================================

    /**
     * Block a user
     */
    public function blockUser(int $user_id, ?string $reason = null): bool
    {
        $sql = "INSERT INTO blocked_users (user_id, blocked_user_id, tenant_id, reason)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiis", $this->user_id, $user_id, $this->tenant_id, $reason);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Unblock a user
     */
    public function unblockUser(int $user_id): bool
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM blocked_users WHERE user_id = ? AND blocked_user_id = ?"
        );
        $stmt->bind_param("ii", $this->user_id, $user_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Check if user is blocked
     */
    public function isBlocked(int $user_id): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_user_id = ?"
        );
        $stmt->bind_param("ii", $this->user_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool) $result;
    }

    /**
     * Check if blocked by user
     */
    public function isBlockedBy(int $user_id): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_user_id = ?"
        );
        $stmt->bind_param("ii", $user_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool) $result;
    }

    /**
     * Get blocked users
     */
    public function getBlockedUsers(): array
    {
        $sql = "
            SELECT 
                bu.*,
                u.first_name,
                u.last_name,
                u.email,
                u.avatar
            FROM blocked_users bu
            JOIN users u ON bu.blocked_user_id = u.id
            WHERE bu.id = ? AND bu.tenant_id = ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $blocked = [];
        while ($row = $result->fetch_assoc()) {
            $blocked[] = $row;
        }
        $stmt->close();

        return $blocked;
    }

    // ==========================================
    // REPORTING
    // ==========================================

    /**
     * Report a message
     */
    public function reportMessage(int $message_id, string $reason, ?string $description = null): int
    {
        $sql = "INSERT INTO message_reports (message_id, reporter_id, tenant_id, reason, description)
                VALUES (?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiss", $message_id, $this->user_id, $this->tenant_id, $reason, $description);

        if (!$stmt->execute()) {
            throw new Exception("Failed to report message");
        }

        $report_id = $this->conn->insert_id;
        $stmt->close();

        return $report_id;
    }

    // ==========================================
    // USER SETTINGS
    // ==========================================

    /**
     * Get user messaging settings
     */
    public function getSettings(): array
    {
        $sql = "SELECT * FROM user_messaging_settings WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $settings = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$settings) {
            // Return defaults
            return [
                'allow_direct_messages' => true,
                'allow_group_invites' => true,
                'show_online_status' => true,
                'show_read_receipts' => true,
                'email_digest_frequency' => 'daily',
                'quiet_hours_start' => null,
                'quiet_hours_end' => null,
                'auto_reply_enabled' => false,
                'auto_reply_message' => null
            ];
        }

        return $settings;
    }

    /**
     * Update user messaging settings
     */
    public function updateSettings(array $data): array
    {
        $sql = "INSERT INTO user_messaging_settings (
            user_id, tenant_id, allow_direct_messages, allow_group_invites,
            show_online_status, show_read_receipts, email_digest_frequency,
            quiet_hours_start, quiet_hours_end, auto_reply_enabled, auto_reply_message
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            allow_direct_messages = VALUES(allow_direct_messages),
            allow_group_invites = VALUES(allow_group_invites),
            show_online_status = VALUES(show_online_status),
            show_read_receipts = VALUES(show_read_receipts),
            email_digest_frequency = VALUES(email_digest_frequency),
            quiet_hours_start = VALUES(quiet_hours_start),
            quiet_hours_end = VALUES(quiet_hours_end),
            auto_reply_enabled = VALUES(auto_reply_enabled),
            auto_reply_message = VALUES(auto_reply_message)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "iiiiiisssss",
            $this->user_id,
            $this->tenant_id,
            $data['allow_direct_messages'] ?? 1,
            $data['allow_group_invites'] ?? 1,
            $data['show_online_status'] ?? 1,
            $data['show_read_receipts'] ?? 1,
            $data['email_digest_frequency'] ?? 'daily',
            $data['quiet_hours_start'] ?? null,
            $data['quiet_hours_end'] ?? null,
            $data['auto_reply_enabled'] ?? 0,
            $data['auto_reply_message'] ?? null
        );

        $stmt->execute();
        $stmt->close();

        return $this->getSettings();
    }

    /**
     * Update conversation notification settings
     */
    public function updateConversationSettings(int $conversation_id, array $data): bool
    {
        $fields = [];
        $params = [];
        $types = "";

        $allowedFields = [
            'is_muted' => 'i', 'muted_until' => 's', 'is_pinned' => 'i',
            'notify_all_messages' => 'i', 'notify_mentions_only' => 'i',
            'notify_email' => 'i', 'notify_push' => 'i'
        ];

        foreach ($allowedFields as $field => $type) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }

        if (empty($fields)) {
            return true;
        }

        $params[] = $conversation_id;
        $params[] = $this->user_id;
        $types .= "ii";

        $sql = "UPDATE conversation_participants SET " . implode(", ", $fields) .
            " WHERE conversation_id = ? AND user_id = ?";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    // ==========================================
    // STATISTICS
    // ==========================================

    /**
     * Get unread counts
     */
    public function getUnreadCounts(): array
    {
        // Total unread messages
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(unread_count), 0) as total_unread
            FROM conversation_participants
            WHERE user_id = ? AND tenant_id = ? AND left_at IS NULL AND is_archived = 0
        ");
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $total_unread = $stmt->get_result()->fetch_assoc()['total_unread'];
        $stmt->close();

        // Unread mentions
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as unread_mentions
            FROM message_mentions mm
            JOIN messages m ON mm.message_id = m.message_id
            WHERE mm.mentioned_user_id = ? AND mm.tenant_id = ? AND mm.is_read = 0 AND m.is_deleted = 0
        ");
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $unread_mentions = $stmt->get_result()->fetch_assoc()['unread_mentions'];
        $stmt->close();

        // Unread broadcasts
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as unread_broadcasts
            FROM broadcast_recipients br
            JOIN broadcast_messages bm ON br.broadcast_id = bm.broadcast_id
            WHERE br.user_id = ? AND br.tenant_id = ? AND br.is_read = 0
            AND bm.status = 'sent' AND (bm.expires_at IS NULL OR bm.expires_at > NOW())
        ");
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $unread_broadcasts = $stmt->get_result()->fetch_assoc()['unread_broadcasts'];
        $stmt->close();

        return [
            'total_unread' => (int) $total_unread,
            'unread_mentions' => (int) $unread_mentions,
            'unread_broadcasts' => (int) $unread_broadcasts
        ];
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    /**
     * Check if user is participant
     */
    private function isParticipant(int $conversation_id): bool
    {
        $stmt = $this->conn->prepare("
            SELECT 1 FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->bind_param("ii", $conversation_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool) $result;
    }

    /**
     * Get participant info
     */
    private function getParticipantInfo(int $conversation_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM conversation_participants 
            WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL
        ");
        $stmt->bind_param("ii", $conversation_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result;
    }

    /**
     * Check conversation permission
     */
    private function checkConversationPermission(int $conversation_id, string $permission): void
    {
        $participant = $this->getParticipantInfo($conversation_id);
        if (!$participant) {
            throw new Exception("You are not a participant in this conversation");
        }

        $allowed = false;
        switch ($permission) {
            case 'edit_settings':
                $allowed = $participant['can_edit_settings'] || in_array($participant['role'], ['owner', 'admin']);
                break;
            case 'add_members':
                $allowed = $participant['can_add_members'] || in_array($participant['role'], ['owner', 'admin']);
                break;
            case 'remove_members':
                $allowed = $participant['can_remove_members'] || in_array($participant['role'], ['owner', 'admin']);
                break;
            case 'pin_messages':
                $allowed = $participant['can_pin_messages'] || in_array($participant['role'], ['owner', 'admin', 'moderator']);
                break;
            case 'delete_messages':
                $allowed = $participant['can_delete_messages'] || in_array($participant['role'], ['owner', 'admin', 'moderator']);
                break;
        }

        if (!$allowed) {
            throw new Exception("You don't have permission to perform this action");
        }
    }

    /**
     * Process message content (links, mentions, formatting)
     */
    private function processMessageContent(string $content): string
    {
        // Convert URLs to links
        $content = preg_replace(
            '/(https?:\/\/[^\s<]+)/',
            '<a href="$1" target="_blank" rel="noopener">$1</a>',
            htmlspecialchars($content)
        );

        // Convert @mentions to links
        $content = preg_replace(
            '/@(\w+)/',
            '<span class="mention" data-user="$1">@$1</span>',
            $content
        );

        // Convert newlines to <br>
        $content = nl2br($content);

        return $content;
    }

    /**
     * Extract mentions from content
     */
    private function extractMentions(string $content): array
    {
        $mentions = [];

        // User mentions: @username or @user_id
        preg_match_all('/@(\w+)/', $content, $matches);
        foreach ($matches[1] as $mention) {
            if (is_numeric($mention)) {
                $mentions['users'][] = (int) $mention;
            } else {
                // Look up username
                $stmt = $this->conn->prepare("SELECT id FROM users WHERE first_name = ? AND tenant_id = ?");
                $stmt->bind_param("si", $mention, $this->tenant_id);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($user) {
                    $mentions['users'][] = $user['user_id'];
                }
            }
        }

        // Role mentions: @everyone, @admins, @instructors
        if (str_contains($content, '@everyone')) {
            $mentions['roles'][] = 'everyone';
        }
        if (str_contains($content, '@admins')) {
            $mentions['roles'][] = 'admins';
        }
        if (str_contains($content, '@instructors')) {
            $mentions['roles'][] = 'instructors';
        }

        return $mentions;
    }

    /**
     * Store mentions
     */
    private function storeMentions(int $message_id, array $mentions): void
    {
        $sql = "INSERT INTO message_mentions (message_id, mentioned_user_id, mentioned_role, tenant_id)
                VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        // User mentions
        foreach ($mentions['users'] ?? [] as $user_id) {
            $role = null;
            $stmt->bind_param("iisi", $message_id, $user_id, $role, $this->tenant_id);
            $stmt->execute();
        }

        // Role mentions
        foreach ($mentions['roles'] ?? [] as $role) {
            $user_id = null;
            $stmt->bind_param("iisi", $message_id, $user_id, $role, $this->tenant_id);
            $stmt->execute();
        }

        $stmt->close();
    }

    /**
     * Update conversation last message
     */
    private function updateConversationLastMessage(int $conversation_id, int $message_id): void
    {
        $sql = "UPDATE conversations SET 
                last_message_id = ?, last_message_at = NOW(), message_count = message_count + 1
                WHERE conversation_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $message_id, $conversation_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update thread stats
     */
    private function updateThreadStats(int $thread_root_id): void
    {
        $sql = "UPDATE messages SET 
                thread_reply_count = thread_reply_count + 1, thread_last_reply_at = NOW()
                WHERE message_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $thread_root_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Increment unread counts for participants
     */
    private function incrementUnreadCounts(int $conversation_id, int $message_id): void
    {
        $sql = "UPDATE conversation_participants SET unread_count = unread_count + 1
                WHERE conversation_id = ? AND user_id != ? AND left_at IS NULL AND is_muted = 0";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $conversation_id, $this->user_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Queue message notifications
     */
    private function queueMessageNotifications(int $conversation_id, int $message_id, array $mentions): void
    {
        // Get participants who should be notified
        $sql = "SELECT user_id, notify_all_messages, notify_mentions_only, notify_email, notify_push
                FROM conversation_participants
                WHERE conversation_id = ? AND user_id != ? AND left_at IS NULL AND is_muted = 0";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $conversation_id, $this->user_id);
        $stmt->execute();
        $participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $mentioned_users = $mentions['users'] ?? [];

        foreach ($participants as $p) {
            $is_mentioned = in_array($p['user_id'], $mentioned_users);

            // Check if should notify
            if (!$p['notify_all_messages'] && !$is_mentioned) {
                continue;
            }

            // Queue in-app notification
            if ($p['notify_push']) {
                $type = $is_mentioned ? 'mention' : 'new_message';
                $this->queueNotification($type, $p['user_id'], $message_id, $conversation_id);
            }

            // Queue email if enabled
            if ($p['notify_email'] && $is_mentioned) {
                $this->queueNotification('mention', $p['user_id'], $message_id, $conversation_id, null, 'email');
            }
        }
    }

    /**
     * Queue notification
     */
    private function queueNotification(
        string $type,
        int $user_id,
        ?int $message_id,
        ?int $conversation_id,
        ?int $broadcast_id = null,
        string $channel = 'in_app'
    ): void {
        $sql = "INSERT INTO messaging_notification_queue (
            tenant_id, notification_type, recipient_user_id, message_id, conversation_id, broadcast_id, channel, scheduled_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isiiiss", $this->tenant_id, $type, $user_id, $message_id, $conversation_id, $broadcast_id, $channel);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Send system message
     */
    private function sendSystemMessage(int $conversation_id, string $action, ?int $target_user_id = null): void
    {
        $content = $target_user_id
            ? "User {$target_user_id} {$action}"
            : "User {$this->user_id} {$action}";

        $sql = "INSERT INTO messages (conversation_id, sender_id, tenant_id, content, message_type)
                VALUES (?, ?, ?, ?, 'system')";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiis", $conversation_id, $this->user_id, $this->tenant_id, $content);
        $stmt->execute();
        $message_id = $this->conn->insert_id;
        $stmt->close();

        $this->updateConversationLastMessage($conversation_id, $message_id);
    }

    /**
     * Get message preview (for replies)
     */
    private function getMessagePreview(int $message_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT m.message_id, m.content, m.sender_id, u.first_name, u.last_name
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.message_id = ?
        ");
        $stmt->bind_param("i", $message_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result) {
            $result['content'] = mb_substr($result['content'], 0, 100);
        }

        return $result;
    }

    /**
     * Get broadcast recipients based on target criteria
     */
    private function getBroadcastRecipients(array $broadcast): array
    {
        $sql = "SELECT id FROM users WHERE tenant_id = ? AND is_active = 1";
        $params = [$this->tenant_id];
        $types = "i";

        switch ($broadcast['target_type']) {
            case 'role':
                $roles = $broadcast['target_criteria']['roles'] ?? [];
                if (!empty($roles)) {
                    $placeholders = implode(',', array_fill(0, count($roles), '?'));
                    $sql .= " AND role IN ($placeholders)";
                    foreach ($roles as $role) {
                        $params[] = $role;
                        $types .= "s";
                    }
                }
                break;

            case 'department':
                $departments = $broadcast['target_criteria']['departments'] ?? [];
                if (!empty($departments)) {
                    $placeholders = implode(',', array_fill(0, count($departments), '?'));
                    $sql .= " AND department_id IN ($placeholders)";
                    foreach ($departments as $dept) {
                        $params[] = $dept;
                        $types .= "i";
                    }
                }
                break;

            case 'course':
                $course_id = $broadcast['target_criteria']['course_id'] ?? null;
                if ($course_id) {
                    $sql = "SELECT DISTINCT user_id FROM course_enrollments WHERE course_id = ? AND tenant_id = ?";
                    $params = [$course_id, $this->tenant_id];
                    $types = "ii";
                }
                break;
        }

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $recipients = [];
        while ($row = $result->fetch_assoc()) {
            $recipients[] = $row['user_id'];
        }
        $stmt->close();

        return $recipients;
    }

    /**
     * Search for users to add to conversations
     */
    public function searchUsers($query, $exclude_ids = []): array
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        // Build exclusion clause
        $exclusion = "";
        if (!empty($exclude_ids)) {
            $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));
            $exclusion = " AND u.id NOT IN ($placeholders)";
        }

        // Search by name or email
        $sql = "SELECT 
                    u.id as user_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.avatar
                FROM users u
                WHERE u.tenant_id = ?
                AND u.is_active = 1
                AND (
                    CONCAT(u.first_name, ' ', u.last_name) LIKE ?
                    OR u.email LIKE ?
                )
                $exclusion
                ORDER BY u.first_name, u.last_name
                LIMIT 10";

        $search_term = "%$query%";
        $params = [$this->tenant_id, $search_term, $search_term];
        $types = "iss";

        // Add exclusion IDs to params
        if (!empty($exclude_ids)) {
            foreach ($exclude_ids as $id) {
                $params[] = $id;
                $types .= "i";
            }
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
        $stmt->close();

        return $users;
    }
}