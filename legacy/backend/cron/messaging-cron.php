<?php

/**
 * Messaging Cron Jobs
 * 
 * This script should be run every minute via cron:
 * * * * * * php /path/to/cron/messaging-cron.php
 * 
 * Handles:
 * - Notification queue processing
 * - Scheduled broadcasts
 * - Email digest generation
 * - Typing indicator cleanup
 * - Message expiry
 * 
 * @package LMS
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/../config/Database.php';

class MessagingCron
{
    private ?mysqli $conn;
    private string $log_file;
    private float $start_time;

    public function __construct()
    {
        $db = new Database();
        $this->conn = $db->getConnection();
        $this->log_file = __DIR__ . '/../logs/messaging-cron.log';
        $this->start_time = microtime(true);
    }

    public function run(): void
    {
        $this->log("=== Starting Messaging Cron ===");

        try {
            $this->processNotificationQueue();
            $this->processScheduledBroadcasts();
            $this->generateEmailDigests();
            $this->cleanupTypingIndicators();
            $this->cleanupExpiredBroadcasts();
            $this->cleanupOldNotifications();
        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
        }

        $elapsed = round(microtime(true) - $this->start_time, 2);
        $this->log("=== Completed in {$elapsed}s ===\n");
    }

    /**
     * Process pending notifications
     */
    private function processNotificationQueue(): void
    {
        $this->log("Processing notification queue...");

        $sql = "SELECT * FROM messaging_notification_queue 
                WHERE status = 'pending' 
                AND scheduled_at <= NOW()
                AND attempts < 3
                ORDER BY scheduled_at ASC
                LIMIT 100";

        $result = $this->conn->query($sql);
        $sent = 0;
        $failed = 0;

        while ($notification = $result->fetch_assoc()) {
            try {
                $this->sendNotification($notification);
                $this->updateNotificationStatus($notification['queue_id'], 'sent');
                $sent++;
            } catch (Exception $e) {
                $this->updateNotificationStatus($notification['queue_id'], 'failed', $e->getMessage());
                $failed++;
            }
        }

        $this->log("  Sent: {$sent}, Failed: {$failed}");
    }

    /**
     * Send a notification
     */
    private function sendNotification(array $notification): void
    {
        switch ($notification['channel']) {
            case 'in_app':
                $this->createInAppNotification($notification);
                break;

            case 'email':
                $this->sendEmailNotification($notification);
                break;

            case 'push':
                $this->sendPushNotification($notification);
                break;
        }
    }

    /**
     * Create in-app notification
     */
    private function createInAppNotification(array $notification): void
    {
        $title = $this->getNotificationTitle($notification);
        $message = $this->getNotificationMessage($notification);
        $data = [
            'type' => $notification['notification_type'],
            'message_id' => $notification['message_id'],
            'conversation_id' => $notification['conversation_id'],
            'broadcast_id' => $notification['broadcast_id']
        ];

        $sql = "INSERT INTO notifications (user_id, tenant_id, title, message, data, type, created_at)
                VALUES (?, ?, ?, ?, ?, 'messaging', NOW())";

        $data_json = json_encode($data);
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "iisss",
            $notification['recipient_user_id'],
            $notification['tenant_id'],
            $title,
            $message,
            $data_json
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Send email notification
     */
    private function sendEmailNotification(array $notification): void
    {
        // Get user email
        $stmt = $this->conn->prepare("SELECT email, first_name FROM users WHERE id = ?");
        $stmt->bind_param("i", $notification['recipient_user_id']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) return;

        $subject = $this->getNotificationTitle($notification);
        $message = $this->getNotificationMessage($notification);

        // Send email (implement with your email service)
        // mail($user['email'], $subject, $message);
        $this->log("    [EMAIL] To: {$user['email']}, Subject: {$subject}");
    }

    /**
     * Send push notification
     */
/*    private function sendPushNotification(array $notification): void
    {
        // Get user's push tokens
        $stmt = $this->conn->prepare("SELECT push_token FROM user_devices WHERE user_id = ? AND push_enabled = 1");
        $stmt->bind_param("i", $notification['recipient_user_id']);
        $stmt->execute();
        $devices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $title = $this->getNotificationTitle($notification);
        $message = $this->getNotificationMessage($notification);

        foreach ($devices as $device) {
            // Send to FCM/APNS (implement with your push service)
            $this->log("    [PUSH] Token: " . substr($device['push_token'], 0, 20) . "...");
        }
    }*/

    /**
     * Get notification title
     */
    private function getNotificationTitle(array $notification): string
    {
        switch ($notification['notification_type']) {
            case 'new_message':
                return 'New Message';
            case 'mention':
                return 'You were mentioned';
            case 'reaction':
                return 'New Reaction';
            case 'reply':
                return 'New Reply';
            case 'broadcast':
                return 'Announcement';
            case 'added_to_group':
                return 'Added to Group';
            default:
                return 'Notification';
        }
    }

    /**
     * Get notification message
     */
    private function getNotificationMessage(array $notification): string
    {
        // Get sender info if message
        if ($notification['message_id']) {
            $stmt = $this->conn->prepare("
                SELECT m.content, u.first_name, u.last_name
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.message_id = ?
            ");
            $stmt->bind_param("i", $notification['message_id']);
            $stmt->execute();
            $message = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($message) {
                $sender = $message['first_name'] . ' ' . $message['last_name'];
                $content = mb_substr($message['content'], 0, 100);
                return "{$sender}: {$content}";
            }
        }

        // Get broadcast info
        if ($notification['broadcast_id']) {
            $stmt = $this->conn->prepare("SELECT title FROM broadcast_messages WHERE broadcast_id = ?");
            $stmt->bind_param("i", $notification['broadcast_id']);
            $stmt->execute();
            $broadcast = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($broadcast) {
                return $broadcast['title'];
            }
        }

        return 'You have a new notification';
    }

    /**
     * Process scheduled broadcasts
     */
    private function processScheduledBroadcasts(): void
    {
        $this->log("Processing scheduled broadcasts...");

        $sql = "SELECT * FROM broadcast_messages 
                WHERE status = 'scheduled' 
                AND scheduled_at <= NOW()";

        $result = $this->conn->query($sql);
        $count = 0;

        while ($broadcast = $result->fetch_assoc()) {
            $this->sendScheduledBroadcast($broadcast);
            $count++;
        }

        $this->log("  Processed {$count} scheduled broadcasts");
    }

    /**
     * Send a scheduled broadcast
     */
    private function sendScheduledBroadcast(array $broadcast): void
    {
        // Get recipients based on target criteria
        $recipients = $this->getBroadcastRecipients($broadcast);

        // Create recipient records
        $sql = "INSERT INTO broadcast_recipients (broadcast_id, user_id, tenant_id) VALUES (?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        foreach ($recipients as $user_id) {
            $stmt->bind_param("iii", $broadcast['broadcast_id'], $user_id, $broadcast['tenant_id']);
            $stmt->execute();
        }
        $stmt->close();

        // Update broadcast status
        $count = count($recipients);
        $sql = "UPDATE broadcast_messages SET 
                status = 'sent', sent_at = NOW(), recipients_count = ?
                WHERE broadcast_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $count, $broadcast['broadcast_id']);
        $stmt->execute();
        $stmt->close();

        // Queue notifications
        foreach ($recipients as $user_id) {
            $this->queueBroadcastNotification($broadcast, $user_id);
        }

        $this->log("    Sent broadcast {$broadcast['broadcast_id']} to {$count} recipients");
    }

    /**
     * Get broadcast recipients
     */
    private function getBroadcastRecipients(array $broadcast): array
    {
        $sql = "SELECT id FROM users WHERE tenant_id = ? AND is_active = 1";
        $params = [$broadcast['tenant_id']];
        $types = "i";

        $target_criteria = json_decode($broadcast['target_criteria'], true) ?? [];

        switch ($broadcast['target_type']) {
            case 'role':
                if (!empty($target_criteria['roles'])) {
                    $placeholders = implode(',', array_fill(0, count($target_criteria['roles']), '?'));
                    $sql .= " AND role IN ($placeholders)";
                    foreach ($target_criteria['roles'] as $role) {
                        $params[] = $role;
                        $types .= "s";
                    }
                }
                break;

            case 'department':
                if (!empty($target_criteria['departments'])) {
                    $placeholders = implode(',', array_fill(0, count($target_criteria['departments']), '?'));
                    $sql .= " AND department_id IN ($placeholders)";
                    foreach ($target_criteria['departments'] as $dept) {
                        $params[] = $dept;
                        $types .= "i";
                    }
                }
                break;

            case 'course':
                if (!empty($target_criteria['course_id'])) {
                    $sql = "SELECT DISTINCT user_id FROM course_enrollments WHERE course_id = ? AND tenant_id = ?";
                    $params = [$target_criteria['course_id'], $broadcast['tenant_id']];
                    $types = "ii";
                }
                break;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
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
     * Queue broadcast notification
     */
    private function queueBroadcastNotification(array $broadcast, int $user_id): void
    {
        $sql = "INSERT INTO messaging_notification_queue (
            tenant_id, notification_type, recipient_user_id, broadcast_id, channel, scheduled_at
        ) VALUES (?, 'broadcast', ?, ?, 'in_app', NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $broadcast['tenant_id'], $user_id, $broadcast['broadcast_id']);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Generate email digests
     */
    private function generateEmailDigests(): void
    {
        $hour = (int) date('H');
        $day = strtolower(date('l'));

        $this->log("Generating email digests...");

        // Hourly digests
        if ($hour >= 8 && $hour <= 20) {
            $this->processDigests('hourly');
        }

        // Daily digests at 9 AM
        if ($hour === 9) {
            $this->processDigests('daily');
        }

        // Weekly digests on Monday at 9 AM
        if ($day === 'monday' && $hour === 9) {
            $this->processDigests('weekly');
        }
    }

    /**
     * Process digests for a specific frequency
     */
    private function processDigests(string $frequency): void
    {
        $sql = "SELECT ums.user_id, ums.tenant_id, u.email, u.first_name
                FROM user_messaging_settings ums
                JOIN users u ON ums.user_id = u.id
                WHERE ums.email_digest_frequency = ?
                AND u.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $frequency);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($users as $user) {
            $this->sendDigestEmail($user, $frequency);
        }

        $this->log("  Processed " . count($users) . " {$frequency} digests");
    }

    /**
     * Send digest email
     */
    private function sendDigestEmail(array $user, string $frequency): void
    {
        // Get unread count
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(unread_count), 0) as total
            FROM conversation_participants
            WHERE user_id = ? AND tenant_id = ? AND left_at IS NULL
        ");
        $stmt->bind_param("ii", $user['user_id'], $user['tenant_id']);
        $stmt->execute();
        $unread = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        if ($unread == 0) return;

        // Get unread broadcasts
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count
            FROM broadcast_recipients br
            JOIN broadcast_messages bm ON br.broadcast_id = bm.broadcast_id
            WHERE br.user_id = ? AND br.is_read = 0 AND bm.status = 'sent'
        ");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $broadcasts = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        // Build and send email
        $subject = "You have {$unread} unread messages";
        $message = "Hi {$user['first_name']},\n\n";
        $message .= "You have {$unread} unread messages";
        if ($broadcasts > 0) {
            $message .= " and {$broadcasts} announcements";
        }
        $message .= ".\n\nLog in to view them.";

        // Send email (implement with your email service)
        // mail($user['email'], $subject, $message);
    }

    /**
     * Cleanup expired typing indicators
     */
    private function cleanupTypingIndicators(): void
    {
        $sql = "DELETE FROM typing_indicators WHERE expires_at < NOW()";
        $this->conn->query($sql);
        $deleted = $this->conn->affected_rows;

        if ($deleted > 0) {
            $this->log("Cleaned up {$deleted} expired typing indicators");
        }
    }

    /**
     * Cleanup expired broadcasts
     */
    private function cleanupExpiredBroadcasts(): void
    {
        $sql = "UPDATE broadcast_messages SET status = 'expired' 
                WHERE status = 'sent' AND expires_at IS NOT NULL AND expires_at < NOW()";
        $this->conn->query($sql);
        $expired = $this->conn->affected_rows;

        if ($expired > 0) {
            $this->log("Marked {$expired} broadcasts as expired");
        }
    }

    /**
     * Cleanup old notifications
     */
    private function cleanupOldNotifications(): void
    {
        // Delete processed notifications older than 7 days
        $sql = "DELETE FROM messaging_notification_queue 
                WHERE status IN ('sent', 'failed') 
                AND processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $this->conn->query($sql);
        $deleted = $this->conn->affected_rows;

        if ($deleted > 0) {
            $this->log("Deleted {$deleted} old notification records");
        }
    }

    /**
     * Update notification status
     */
    private function updateNotificationStatus(int $queue_id, string $status, ?string $error = null): void
    {
        $sql = "UPDATE messaging_notification_queue 
                SET status = ?, processed_at = NOW(), error_message = ?, attempts = attempts + 1 
                WHERE queue_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssi", $status, $error, $queue_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Write to log file
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}\n";
        
        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        file_put_contents($this->log_file, $log_message, FILE_APPEND);
        echo $log_message;
    }
}

// Run the cron
$cron = new MessagingCron();
$cron->run();
