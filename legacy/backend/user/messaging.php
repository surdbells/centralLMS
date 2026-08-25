<?php

/**
 * Messaging API Endpoint
 *
 * Handles all messaging operations via RESTful API
 *
 * @package LMS
 * @version 1.0.0
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/Messaging.php';

$db = new Database();
$conn = $db->getConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $db->send_response(400, 'error', null, 'Invalid JSON input');
        exit;
    }

    // Authentication
    $token = $input['token'] ?? null;
    $tenant_id = (int) ($input['tenant_id'] ?? 0);
    $user_id = (int) ($input['user_id'] ?? 0);
    $action = $input['action'] ?? '';

    if (!$token || !$tenant_id || !$user_id) {
        $db->send_response(401, 'error', null, 'Authentication required');
        exit;
    }

    // Validate token here (your existing auth logic)
    // ...

    $messaging = new Messaging($conn, $tenant_id, $user_id);

    switch ($action) {
        // ==========================================
        // CONVERSATIONS
        // ==========================================

        case 'create-conversation':
            $data = $messaging->createConversation($input);
            $db->send_response(201, 'success', $data, 'Conversation created');
            break;

        case 'get-conversation':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $data = $messaging->getConversation($conversation_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Conversation not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Conversation retrieved');
            break;

        case 'get-conversations':
            $filters = [
                'type' => $input['type'] ?? null,
                'is_archived' => $input['is_archived'] ?? null,
                'search' => $input['search'] ?? null,
                'limit' => $input['limit'] ?? 50,
                'offset' => $input['offset'] ?? 0
            ];
            $data = $messaging->getConversations(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Conversations retrieved');
            break;

        case 'update-conversation':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $data = $messaging->updateConversation($conversation_id, $input);
            $db->send_response(200, 'success', $data, 'Conversation updated');
            break;

        case 'archive-conversation':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            $archive = $input['archive'] ?? true;
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $messaging->archiveConversation($conversation_id, $archive);
            $db->send_response(200, 'success', null, $archive ? 'Conversation archived' : 'Conversation unarchived');
            break;

        case 'leave-conversation':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $messaging->leaveConversation($conversation_id);
            $db->send_response(200, 'success', null, 'Left conversation');
            break;

        case 'start-direct-message':
            $recipient_id = (int) ($input['recipient_id'] ?? 0);
            if (!$recipient_id) {
                $db->send_response(400, 'error', null, 'Recipient ID required');
                break;
            }
            // Find or create direct conversation
            $existing = $messaging->findDirectConversation($recipient_id);
            if ($existing) {
                $db->send_response(200, 'success', $existing, 'Existing conversation found');
            } else {
                $data = $messaging->createConversation([
                    'type' => 'direct',
                    'participants' => [$recipient_id]
                ]);
                $db->send_response(201, 'success', $data, 'Conversation created');
            }
            break;

        // ==========================================
        // PARTICIPANTS
        // ==========================================

        case 'add-participant':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            $participant_id = (int) ($input['participant_id'] ?? 0);
            $role = $input['role'] ?? 'member';
            if (!$conversation_id || !$participant_id) {
                $db->send_response(400, 'error', null, 'Conversation ID and participant ID required');
                break;
            }
            $messaging->addParticipant($conversation_id, $participant_id, $role);
            $db->send_response(200, 'success', null, 'Participant added');
            break;

        case 'add-participants':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            $participant_ids = $input['participant_ids'] ?? [];
            if (!$conversation_id || empty($participant_ids)) {
                $db->send_response(400, 'error', null, 'Conversation ID and participant IDs required');
                break;
            }
            foreach ($participant_ids as $pid) {
                $messaging->addParticipant($conversation_id, (int) $pid, 'member');
            }
            $db->send_response(200, 'success', null, 'Participants added');
            break;

        case 'remove-participant':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            $participant_id = (int) ($input['participant_id'] ?? 0);
            if (!$conversation_id || !$participant_id) {
                $db->send_response(400, 'error', null, 'Conversation ID and participant ID required');
                break;
            }
            $messaging->removeParticipant($conversation_id, $participant_id);
            $db->send_response(200, 'success', null, 'Participant removed');
            break;

        case 'get-participants':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $data = $messaging->getParticipants($conversation_id);
            $db->send_response(200, 'success', $data, 'Participants retrieved');
            break;

        case 'update-participant-role':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            $participant_id = (int) ($input['participant_id'] ?? 0);
            $role = $input['role'] ?? '';
            if (!$conversation_id || !$participant_id || !$role) {
                $db->send_response(400, 'error', null, 'Conversation ID, participant ID and role required');
                break;
            }
            $messaging->updateParticipantRole($conversation_id, $participant_id, $role);
            $db->send_response(200, 'success', null, 'Role updated');
            break;

        // ==========================================
        // MESSAGES
        // ==========================================

        case 'send-message':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $data = $messaging->sendMessage($conversation_id, $input);
            $db->send_response(201, 'success', $data, 'Message sent');
            break;

        case 'get-message':
            $message_id = (int) ($input['message_id'] ?? 0);
            if (!$message_id) {
                $db->send_response(400, 'error', null, 'Message ID required');
                break;
            }
            $data = $messaging->getMessage($message_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Message not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Message retrieved');
            break;

        case 'get-messages':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $options = [
                'before_id' => $input['before_id'] ?? null,
                'after_id' => $input['after_id'] ?? null,
                'thread_root_id' => $input['thread_root_id'] ?? null,
                'pinned_only' => $input['pinned_only'] ?? false,
                'limit' => $input['limit'] ?? 50
            ];
            $data = $messaging->getMessages($conversation_id, array_filter($options, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Messages retrieved');
            break;

        case 'edit-message':
            $message_id = (int) ($input['message_id'] ?? 0);
            $content = $input['content'] ?? '';
            if (!$message_id || !$content) {
                $db->send_response(400, 'error', null, 'Message ID and content required');
                break;
            }
            $data = $messaging->editMessage($message_id, $content);
            $db->send_response(200, 'success', $data, 'Message edited');
            break;

        case 'delete-message':
            $message_id = (int) ($input['message_id'] ?? 0);
            $for_everyone = $input['for_everyone'] ?? false;
            if (!$message_id) {
                $db->send_response(400, 'error', null, 'Message ID required');
                break;
            }
            $messaging->deleteMessage($message_id, $for_everyone);
            $db->send_response(200, 'success', null, 'Message deleted');
            break;

        case 'pin-message':
            $message_id = (int) ($input['message_id'] ?? 0);
            $pin = $input['pin'] ?? true;
            if (!$message_id) {
                $db->send_response(400, 'error', null, 'Message ID required');
                break;
            }
            $messaging->pinMessage($message_id, $pin);
            $db->send_response(200, 'success', null, $pin ? 'Message pinned' : 'Message unpinned');
            break;

        case 'forward-message':
            $message_id = (int) ($input['message_id'] ?? 0);
            $conversation_ids = $input['conversation_ids'] ?? [];
            if (!$message_id || empty($conversation_ids)) {
                $db->send_response(400, 'error', null, 'Message ID and conversation IDs required');
                break;
            }
            $data = $messaging->forwardMessage($message_id, $conversation_ids);
            $db->send_response(200, 'success', $data, 'Message forwarded');
            break;

        // ==========================================
        // REACTIONS
        // ==========================================

        case 'add-reaction':
            $message_id = (int) ($input['message_id'] ?? 0);
            $emoji = $input['emoji'] ?? '';
            if (!$message_id || !$emoji) {
                $db->send_response(400, 'error', null, 'Message ID and emoji required');
                break;
            }
            $messaging->addReaction($message_id, $emoji);
            $db->send_response(200, 'success', null, 'Reaction added');
            break;

        case 'remove-reaction':
            $message_id = (int) ($input['message_id'] ?? 0);
            $emoji = $input['emoji'] ?? '';
            if (!$message_id || !$emoji) {
                $db->send_response(400, 'error', null, 'Message ID and emoji required');
                break;
            }
            $messaging->removeReaction($message_id, $emoji);
            $db->send_response(200, 'success', null, 'Reaction removed');
            break;

        // ==========================================
        // READ RECEIPTS & TYPING
        // ==========================================

        case 'mark-as-read':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            $up_to_message_id = $input['up_to_message_id'] ?? null;
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $messaging->markAsRead($conversation_id, $up_to_message_id);
            $db->send_response(200, 'success', null, 'Marked as read');
            break;

        case 'get-read-receipts':
            $message_id = (int) ($input['message_id'] ?? 0);
            if (!$message_id) {
                $db->send_response(400, 'error', null, 'Message ID required');
                break;
            }
            $data = $messaging->getReadReceipts($message_id);
            $db->send_response(200, 'success', $data, 'Read receipts retrieved');
            break;

        case 'set-typing':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            $typing = $input['typing'] ?? true;
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $messaging->setTyping($conversation_id, $typing);
            $db->send_response(200, 'success', null, 'Typing status updated');
            break;

        case 'get-typing-users':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $data = $messaging->getTypingUsers($conversation_id);
            $db->send_response(200, 'success', $data, 'Typing users retrieved');
            break;

        // ==========================================
        // SEARCH
        // ==========================================

        case 'search-messages':
            $query = $input['query'] ?? '';
            if (!$query) {
                $db->send_response(400, 'error', null, 'Search query required');
                break;
            }
            $options = [
                'conversation_id' => $input['conversation_id'] ?? null,
                'sender_id' => $input['sender_id'] ?? null,
                'from_date' => $input['from_date'] ?? null,
                'to_date' => $input['to_date'] ?? null,
                'has_attachments' => $input['has_attachments'] ?? false,
                'limit' => $input['limit'] ?? 50
            ];
            $data = $messaging->searchMessages($query, array_filter($options, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Search results');
            break;

        // ==========================================
        // DRAFTS
        // ==========================================

        case 'save-draft':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            $content = $input['content'] ?? '';
            $reply_to_id = $input['reply_to_id'] ?? null;
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $messaging->saveDraft($conversation_id, $content, $reply_to_id);
            $db->send_response(200, 'success', null, 'Draft saved');
            break;

        case 'get-draft':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $data = $messaging->getDraft($conversation_id);
            $db->send_response(200, 'success', $data, 'Draft retrieved');
            break;

        case 'delete-draft':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $messaging->deleteDraft($conversation_id);
            $db->send_response(200, 'success', null, 'Draft deleted');
            break;

        // ==========================================
        // SAVED MESSAGES
        // ==========================================

        case 'save-message':
            $message_id = (int) ($input['message_id'] ?? 0);
            $note = $input['note'] ?? null;
            if (!$message_id) {
                $db->send_response(400, 'error', null, 'Message ID required');
                break;
            }
            $messaging->saveMessage($message_id, $note);
            $db->send_response(200, 'success', null, 'Message saved');
            break;

        case 'unsave-message':
            $message_id = (int) ($input['message_id'] ?? 0);
            if (!$message_id) {
                $db->send_response(400, 'error', null, 'Message ID required');
                break;
            }
            $messaging->unsaveMessage($message_id);
            $db->send_response(200, 'success', null, 'Message unsaved');
            break;

        case 'get-saved-messages':
            $limit = $input['limit'] ?? 50;
            $offset = $input['offset'] ?? 0;
            $data = $messaging->getSavedMessages($limit, $offset);
            $db->send_response(200, 'success', $data, 'Saved messages retrieved');
            break;

        // ==========================================
        // BROADCASTS
        // ==========================================

        case 'create-broadcast':
            $data = $messaging->createBroadcast($input);
            $db->send_response(201, 'success', $data, 'Broadcast created');
            break;

        case 'send-broadcast':
            $broadcast_id = (int) ($input['broadcast_id'] ?? 0);
            if (!$broadcast_id) {
                $db->send_response(400, 'error', null, 'Broadcast ID required');
                break;
            }
            $data = $messaging->sendBroadcast($broadcast_id);
            $db->send_response(200, 'success', $data, 'Broadcast sent');
            break;

        case 'get-broadcast':
            $broadcast_id = (int) ($input['broadcast_id'] ?? 0);
            if (!$broadcast_id) {
                $db->send_response(400, 'error', null, 'Broadcast ID required');
                break;
            }
            $data = $messaging->getBroadcast($broadcast_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Broadcast not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Broadcast retrieved');
            break;

        case 'get-my-broadcasts':
            $unread_only = $input['unread_only'] ?? false;
            $data = $messaging->getMyBroadcasts($unread_only);
            $db->send_response(200, 'success', $data, 'Broadcasts retrieved');
            break;

        case 'mark-broadcast-read':
            $broadcast_id = (int) ($input['broadcast_id'] ?? 0);
            if (!$broadcast_id) {
                $db->send_response(400, 'error', null, 'Broadcast ID required');
                break;
            }
            $messaging->markBroadcastRead($broadcast_id);
            $db->send_response(200, 'success', null, 'Broadcast marked as read');
            break;

        case 'acknowledge-broadcast':
            $broadcast_id = (int) ($input['broadcast_id'] ?? 0);
            if (!$broadcast_id) {
                $db->send_response(400, 'error', null, 'Broadcast ID required');
                break;
            }
            $messaging->acknowledgeBroadcast($broadcast_id);
            $db->send_response(200, 'success', null, 'Broadcast acknowledged');
            break;

        // ==========================================
        // BLOCKING
        // ==========================================

        case 'block-user':
            $blocked_user_id = (int) ($input['blocked_user_id'] ?? 0);
            $reason = $input['reason'] ?? null;
            if (!$blocked_user_id) {
                $db->send_response(400, 'error', null, 'User ID required');
                break;
            }
            $messaging->blockUser($blocked_user_id, $reason);
            $db->send_response(200, 'success', null, 'User blocked');
            break;

        case 'unblock-user':
            $blocked_user_id = (int) ($input['blocked_user_id'] ?? 0);
            if (!$blocked_user_id) {
                $db->send_response(400, 'error', null, 'User ID required');
                break;
            }
            $messaging->unblockUser($blocked_user_id);
            $db->send_response(200, 'success', null, 'User unblocked');
            break;

        case 'get-blocked-users':
            $data = $messaging->getBlockedUsers();
            $db->send_response(200, 'success', $data, 'Blocked users retrieved');
            break;

        // ==========================================
        // REPORTING
        // ==========================================

        case 'report-message':
            $message_id = (int) ($input['message_id'] ?? 0);
            $reason = $input['reason'] ?? '';
            $description = $input['description'] ?? null;
            if (!$message_id || !$reason) {
                $db->send_response(400, 'error', null, 'Message ID and reason required');
                break;
            }
            $report_id = $messaging->reportMessage($message_id, $reason, $description);
            $db->send_response(200, 'success', ['report_id' => $report_id], 'Message reported');
            break;

        // ==========================================
        // SETTINGS
        // ==========================================

        case 'get-settings':
            $data = $messaging->getSettings();
            $db->send_response(200, 'success', $data, 'Settings retrieved');
            break;

        case 'update-settings':
            $data = $messaging->updateSettings($input);
            $db->send_response(200, 'success', $data, 'Settings updated');
            break;

        case 'update-conversation-settings':
            $conversation_id = (int) ($input['conversation_id'] ?? 0);
            if (!$conversation_id) {
                $db->send_response(400, 'error', null, 'Conversation ID required');
                break;
            }
            $messaging->updateConversationSettings($conversation_id, $input);
            $db->send_response(200, 'success', null, 'Conversation settings updated');
            break;

        // ==========================================
        // STATISTICS
        // ==========================================

        case 'search-users':
            $query = $input['query'] ?? '';
            $exclude_ids = $input['exclude_ids'] ?? [];
            $data = $messaging->searchUsers($query, $exclude_ids);
            $db->send_response(200, 'success', $data, 'Users found');
            break;

        case 'get-unread-counts':
            $data = $messaging->getUnreadCounts();
            $db->send_response(200, 'success', $data, 'Unread counts retrieved');
            break;

        default:
            $db->send_response(400, 'error', null,
                'Invalid action. Available actions: ' .
                'create-conversation, get-conversation, get-conversations, update-conversation, archive-conversation, leave-conversation, start-direct-message, ' .
                'add-participant, add-participants, remove-participant, get-participants, update-participant-role, ' .
                'send-message, get-message, get-messages, edit-message, delete-message, pin-message, forward-message, ' .
                'add-reaction, remove-reaction, ' .
                'mark-as-read, get-read-receipts, set-typing, get-typing-users, ' .
                'search-messages, search-users, ' .
                'save-draft, get-draft, delete-draft, ' .
                'save-message, unsave-message, get-saved-messages, ' .
                'create-broadcast, send-broadcast, get-broadcast, get-my-broadcasts, mark-broadcast-read, acknowledge-broadcast, ' .
                'block-user, unblock-user, get-blocked-users, ' .
                'report-message, ' .
                'get-settings, update-settings, update-conversation-settings, ' .
                'get-unread-counts');
    }

} catch (Exception $e) {
    error_log('Messaging API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, $e->getMessage());
}