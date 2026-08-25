<?php

/**
* Live Training Cron Jobs
*
* This script should be run every 5 minutes via cron:
* * /5 * * * * php /path/to/cron/live-training-cron.php
*
* Handles:
* - Session reminders (24h, 1h, 15m before)
* - Auto-marking absent for past sessions
* - Session status updates
* - Notification queue processing
*
*  @package LMS
*  @version 1.0.0
*/

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/LiveTraining.php';

class LiveTrainingCron
{
    private ?mysqli $conn;
    private string $log_file;
    private float $start_time;

    public function __construct()
    {
        $db = new Database();
        $this->conn = $db->getConnection();
        $this->log_file = __DIR__ . '/../logs/live-training-cron.log';
        $this->start_time = microtime(true);
    }

    /**
     * Run all cron tasks
     */
    public function run(): void
    {
        $this->log("=== Starting Live Training Cron ===");

        try {
            $this->processReminders();
            $this->updateSessionStatuses();
            $this->autoMarkAbsent();
            $this->processNotificationQueue();
            $this->cleanupOldData();
        } catch (Exception $e) {
            $this->log("ERROR: " . $e->getMessage());
        }

        $elapsed = round(microtime(true) - $this->start_time, 2);
        $this->log("=== Completed in {$elapsed}s ===\n");
    }

    /**
     * Process pending session reminders
     */
    private function processReminders(): void
    {
        $this->log("Processing reminders...");

        // Get pending reminders that are due
        $sql = "SELECT 
                    r.*,
                    s.title as session_title,
                    s.session_date,
                    s.start_time,
                    s.training_id,
                    t.title as training_title,
                    t.reminder_channels
                FROM live_training_reminders r
                JOIN live_training_sessions s ON r.session_id = s.session_id
                JOIN live_trainings t ON s.training_id = t.training_id
                WHERE r.status = 'pending' 
                AND r.scheduled_at <= NOW()
                AND s.status = 'scheduled'
                LIMIT 50";

        $result = $this->conn->query($sql);
        $count = 0;

        while ($reminder = $result->fetch_assoc()) {
            try {
                $this->sendReminder($reminder);
                $count++;
            } catch (Exception $e) {
                $this->log("  Error sending reminder {$reminder['reminder_id']}: " . $e->getMessage());
                $this->updateReminderStatus($reminder['reminder_id'], 'failed', $e->getMessage());
            }
        }

        $this->log("  Processed {$count} reminders");
    }

    /**
     * Send a reminder notification
     */
    private function sendReminder(array $reminder): void
    {
        $channels = json_decode($reminder['reminder_channels'], true) ?: ['email', 'in_app'];
        $recipients_count = 0;
        $successful_count = 0;

        // Get enrolled learners
        $sql = "SELECT 
                    e.user_id,
                    u.email,
                    u.first_name,
                    u.phone
                FROM live_training_enrollments e
                JOIN users u ON e.user_id = u.id
                WHERE e.training_id = ? AND e.status = 'enrolled'";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $reminder['training_id']);
        $stmt->execute();
        $learners = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get instructors
        $sql = "SELECT 
                    i.user_id,
                    u.email,
                    u.first_name,
                    u.phone
                FROM live_training_instructors i
                JOIN users u ON i.user_id = u.id
                WHERE i.session_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $reminder['session_id']);
        $stmt->execute();
        $instructors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $all_recipients = array_merge($learners, $instructors);
        $reminder_text = $this->getReminderTimeText($reminder['reminder_type']);
        $session_datetime = $reminder['session_date'] . ' ' . $reminder['start_time'];

        foreach ($all_recipients as $recipient) {
            $recipients_count++;
            $message = "Reminder: {$reminder['session_title']} starts {$reminder_text} ({$session_datetime})";

            foreach ($channels as $channel) {
                try {
                    $this->queueNotification(
                        $reminder['tenant_id'],
                        'reminder',
                        $recipient['user_id'],
                        $recipient['email'],
                        $recipient['phone'] ?? null,
                        $channel,
                        "Session Reminder: {$reminder['session_title']}",
                        $message,
                        [
                            'training_id' => $reminder['training_id'],
                            'session_id' => $reminder['session_id'],
                            'reminder_type' => $reminder['reminder_type']
                        ]
                    );
                    $successful_count++;
                } catch (Exception $e) {
                    $this->log("    Failed to queue {$channel} for user {$recipient['user_id']}");
                }
            }
        }

        // Update reminder status
        $sql = "UPDATE live_training_reminders SET 
                status = 'sent',
                sent_at = NOW(),
                recipients_count = ?,
                successful_count = ?,
                channels_used = ?
                WHERE reminder_id = ?";

        $channels_json = json_encode($channels);
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iisi", $recipients_count, $successful_count, $channels_json, $reminder['reminder_id']);
        $stmt->execute();
        $stmt->close();

        // Log reminder to reminder_log table
        $this->logReminderRecipients($reminder['reminder_id'], $all_recipients, $channels);
    }

    /**
     * Queue a notification for processing
     */
    private function queueNotification(
        int $tenant_id,
        string $type,
        int $user_id,
        ?string $email,
        ?string $phone,
        string $channel,
        string $subject,
        string $message,
        array $data
    ): void {
        $sql = "INSERT INTO live_training_notification_queue (
            tenant_id, notification_type, recipient_user_id, recipient_email, recipient_phone,
            channel, subject, message, data, scheduled_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $data_json = json_encode($data);
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "isissssss",
            $tenant_id, $type, $user_id, $email, $phone,
            $channel, $subject, $message, $data_json
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Process notification queue
     */
    private function processNotificationQueue(): void
    {
        $this->log("Processing notification queue...");

        $sql = "SELECT * FROM live_training_notification_queue 
                WHERE status = 'pending' 
                AND scheduled_at <= NOW()
                AND attempts < max_attempts
                ORDER BY scheduled_at ASC
                LIMIT 100";

        $result = $this->conn->query($sql);
        $sent = 0;
        $failed = 0;

        while ($notification = $result->fetch_assoc()) {
            $success = $this->sendNotification($notification);
            if ($success) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $this->log("  Sent: {$sent}, Failed: {$failed}");
    }

    /**
     * Send a single notification
     */
    private function sendNotification(array $notification): bool
    {
        try {
            $success = false;

            switch ($notification['channel']) {
                case 'email':
                    $success = $this->sendEmail(
                        $notification['recipient_email'],
                        $notification['subject'],
                        $notification['message']
                    );
                    break;

                case 'in_app':
                    $success = $this->createInAppNotification(
                        $notification['recipient_user_id'],
                        $notification['tenant_id'],
                        $notification['subject'],
                        $notification['message'],
                        json_decode($notification['data'], true)
                    );
                    break;

                case 'sms':
                    $success = $this->sendSMS(
                        $notification['recipient_phone'],
                        $notification['message']
                    );
                    break;

                case 'whatsapp':
                    $success = $this->sendWhatsApp(
                        $notification['recipient_phone'],
                        $notification['message']
                    );
                    break;
            }

            $status = $success ? 'sent' : 'failed';
            $this->updateNotificationStatus($notification['queue_id'], $status);

            return $success;

        } catch (Exception $e) {
            $this->updateNotificationStatus($notification['queue_id'], 'failed', $e->getMessage());
            return false;
        }
    }

    /**
     * Send email notification
     */
    private function sendEmail(string $to, string $subject, string $message): bool
    {
        // Use your email service (PHPMailer, SendGrid, etc.)
        // This is a placeholder implementation

        $headers = [
            'From' => 'noreply@yourlms.com',
            'Content-Type' => 'text/html; charset=UTF-8'
        ];

        // For production, use proper email library
        // return mail($to, $subject, $message, $headers);

        $this->log("    [EMAIL] To: {$to}, Subject: {$subject}");
        return true; // Placeholder
    }

    /**
     * Create in-app notification
     */
    private function createInAppNotification(int $user_id, int $tenant_id, string $title, string $message, array $data): bool
    {
        // Insert into your notifications table
        $sql = "INSERT INTO notifications (user_id, tenant_id, title, message, data, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())";

        $data_json = json_encode($data);
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iisss", $user_id, $tenant_id, $title, $message, $data_json);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Send SMS notification (placeholder)
     */
    private function sendSMS(?string $phone, string $message): bool
    {
        if (!$phone) return false;

        // Implement with Twilio, Nexmo, etc.
        $this->log("    [SMS] To: {$phone}");
        return true; // Placeholder
    }

    /**
     * Send WhatsApp notification (placeholder)
     */
    private function sendWhatsApp(?string $phone, string $message): bool
    {
        if (!$phone) return false;

        // Implement with WhatsApp Business API
        $this->log("    [WHATSAPP] To: {$phone}");
        return true; // Placeholder
    }

    /**
     * Update session statuses
     */
    private function updateSessionStatuses(): void
    {
        $this->log("Updating session statuses...");

        // Mark sessions as ongoing
        $sql = "UPDATE live_training_sessions 
                SET status = 'ongoing', actual_start_time = NOW()
                WHERE status = 'scheduled' 
                AND CONCAT(session_date, ' ', start_time) <= NOW()
                AND CONCAT(session_date, ' ', end_time) > NOW()";

        $this->conn->query($sql);
        $ongoing = $this->conn->affected_rows;

        // Mark sessions as completed
        $sql = "UPDATE live_training_sessions 
                SET status = 'completed', actual_end_time = NOW()
                WHERE status IN ('scheduled', 'ongoing')
                AND CONCAT(session_date, ' ', end_time) < NOW()";

        $this->conn->query($sql);
        $completed = $this->conn->affected_rows;

        // Update training status based on sessions
        $sql = "UPDATE live_trainings lt
                SET status = 'ongoing'
                WHERE status = 'published'
                AND EXISTS (
                    SELECT 1 FROM live_training_sessions s 
                    WHERE s.training_id = lt.training_id 
                    AND s.status = 'ongoing'
                )";
        $this->conn->query($sql);

        // Mark trainings as completed when all sessions are done
        $sql = "UPDATE live_trainings lt
                SET status = 'completed'
                WHERE status IN ('published', 'ongoing')
                AND NOT EXISTS (
                    SELECT 1 FROM live_training_sessions s 
                    WHERE s.training_id = lt.training_id 
                    AND s.status IN ('scheduled', 'ongoing')
                )
                AND EXISTS (
                    SELECT 1 FROM live_training_sessions s 
                    WHERE s.training_id = lt.training_id 
                    AND s.status = 'completed'
                )";
        $this->conn->query($sql);

        $this->log("  Ongoing: {$ongoing}, Completed: {$completed}");
    }

    /**
     * Auto-mark absent for learners who didn't attend completed sessions
     */
    private function autoMarkAbsent(): void
    {
        $this->log("Auto-marking absent attendance...");

        // Get recently completed sessions that aren't locked
        $sql = "SELECT session_id, training_id, tenant_id 
                FROM live_training_sessions 
                WHERE status = 'completed' 
                AND attendance_locked = 0
                AND actual_end_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";

        $result = $this->conn->query($sql);
        $count = 0;

        while ($session = $result->fetch_assoc()) {
            // Update any unmarked attendance to absent
            $sql = "UPDATE live_training_attendance 
                    SET status = 'absent', marked_at = NOW()
                    WHERE session_id = ? 
                    AND status = 'absent' 
                    AND marked_at IS NULL";

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $session['session_id']);
            $stmt->execute();
            $count += $stmt->affected_rows;
            $stmt->close();

            // Update enrollment attendance percentages
            $sql = "UPDATE live_training_enrollments lte
                    SET 
                        sessions_attended = (
                            SELECT COUNT(*) FROM live_training_attendance lta
                            WHERE lta.enrollment_id = lte.enrollment_id 
                            AND lta.status IN ('present', 'late')
                        ),
                        attendance_percentage = (
                            SELECT ROUND(
                                COUNT(CASE WHEN lta.status IN ('present', 'late') THEN 1 END) * 100.0 / 
                                NULLIF(COUNT(*), 0), 2
                            )
                            FROM live_training_attendance lta
                            WHERE lta.enrollment_id = lte.enrollment_id
                        )
                    WHERE lte.training_id = ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $session['training_id']);
            $stmt->execute();
            $stmt->close();
        }

        $this->log("  Marked {$count} attendance records as absent");
    }

    /**
     * Cleanup old data
     */
    private function cleanupOldData(): void
    {
        $this->log("Cleaning up old data...");

        // Delete processed notifications older than 30 days
        $sql = "DELETE FROM live_training_notification_queue 
                WHERE status IN ('sent', 'failed') 
                AND processed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $this->conn->query($sql);
        $deleted = $this->conn->affected_rows;

        // Delete old attendance logs older than 1 year
        $sql = "DELETE FROM live_training_attendance_log 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)";
        $this->conn->query($sql);

        $this->log("  Deleted {$deleted} old notification records");
    }

    /**
     * Update reminder status
     */
    private function updateReminderStatus(int $reminder_id, string $status, ?string $error = null): void
    {
        $sql = "UPDATE live_training_reminders SET status = ?, error_message = ? WHERE reminder_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssi", $status, $error, $reminder_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update notification status
     */
    private function updateNotificationStatus(int $queue_id, string $status, ?string $error = null): void
    {
        $sql = "UPDATE live_training_notification_queue 
                SET status = ?, processed_at = NOW(), error_message = ?, attempts = attempts + 1 
                WHERE queue_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssi", $status, $error, $queue_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Log reminder recipients
     */
    private function logReminderRecipients(int $reminder_id, array $recipients, array $channels): void
    {
        $sql = "INSERT INTO live_training_reminder_log (reminder_id, user_id, tenant_id, recipient_type, channel, status, sent_at)
                VALUES (?, ?, ?, ?, ?, 'sent', NOW())";

        $stmt = $this->conn->prepare($sql);

        foreach ($recipients as $recipient) {
            foreach ($channels as $channel) {
                $type = isset($recipient['role']) ? 'instructor' : 'learner';
                $tenant_id = 1; // Get from context
                $stmt->bind_param("iiiss", $reminder_id, $recipient['user_id'], $tenant_id, $type, $channel);
                $stmt->execute();
            }
        }

        $stmt->close();
    }

    /**
     * Get reminder time text
     */
    private function getReminderTimeText(string $type): string
    {
        switch ($type) {
            case '24h': return 'in 24 hours';
            case '1h': return 'in 1 hour';
            case '15m': return 'in 15 minutes';
            default: return 'soon';
        }
    }

    /**
     * Write to log file
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}\n";

        // Ensure log directory exists
        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }

        file_put_contents($this->log_file, $log_message, FILE_APPEND);

        // Also output to console
        echo $log_message;
    }
}

// Run the cron
$cron = new LiveTrainingCron();
$cron->run();