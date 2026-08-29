<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * A rich, branded HTML body for each kind of email, as the starting point an
 * author edits.
 *
 * The plain sentences in {@see NotificationComposer} are right for the bell,
 * where markup would be noise. Email is a page someone opens, and a wall of one
 * grey sentence reads as an afterthought. This gives each emailable type a
 * laid-out default — a greeting, the message, a call to action where the type
 * has a link — written against the same closed placeholder list the renderer
 * substitutes ({@see EmailTemplateRenderer::PLACEHOLDERS}), so an organisation
 * that adopts it as-is still gets the person's name, the course, the link.
 */
final class EmailTemplateDefaults
{
    /** The rich HTML body for a type, or null for types with no rich default. */
    public function body(NotificationType $type): ?string
    {
        $inner = match ($type) {
            NotificationType::Welcome => $this->paragraphs([
                'Welcome to {{organisation}}. Your account is ready.',
                'Everything assigned to you is waiting on your dashboard — your courses, your certificates and your progress in one place.',
            ]) . $this->button('Go to my dashboard', '{{link}}'),

            NotificationType::Invitation => $this->paragraphs([
                'You have been invited to join <strong>{{organisation}}</strong>.',
                'An account has been created for you. Choose a password to get started — the link works for {{expires_in}}.',
            ]) . $this->button('Set my password', '{{link}}'),

            NotificationType::PasswordReset => $this->paragraphs([
                'We received a request to reset the password on your {{organisation}} account.',
                'Choose a new password using the button below. The link works for {{expires_in}}. If you did not ask for this, you can safely ignore this email — nothing has changed.',
            ]) . $this->button('Reset my password', '{{link}}'),

            NotificationType::EnrollmentAssigned => $this->paragraphs([
                '<strong>{{subject}}</strong> has been added to your learning.',
                'It is due by {{due_date}}. Open it from your dashboard whenever you are ready to begin.',
            ]) . $this->button('Start learning', '{{link}}'),

            NotificationType::EnrollmentDueSoon => $this->paragraphs([
                'A reminder that <strong>{{subject}}</strong> is due soon — by {{due_date}}.',
                'You have not finished it yet. A little time now keeps you on track.',
            ]) . $this->button('Continue', '{{link}}'),

            NotificationType::EnrollmentOverdue => $this->paragraphs([
                '<strong>{{subject}}</strong> has passed its due date of {{due_date}}.',
                'Please complete it as soon as you can.',
            ]) . $this->button('Finish now', '{{link}}'),

            NotificationType::CourseCompleted => $this->paragraphs([
                'Congratulations — you have completed <strong>{{subject}}</strong>.',
                'You scored {{percentage}}%. Well done on getting it finished.',
            ]),

            NotificationType::AssessmentGraded => $this->paragraphs([
                'Your attempt at <strong>{{subject}}</strong> has been marked.',
                'You scored {{percentage}}%. Open it to see the feedback in full.',
            ]) . $this->button('See my result', '{{link}}'),

            NotificationType::CertificateIssued => $this->paragraphs([
                'Your certificate for <strong>{{subject}}</strong> is ready.',
                'Certificate {{certificate_number}} has been issued. You can download or share it from your certificates.',
            ]) . $this->button('View my certificate', '{{link}}'),

            NotificationType::CertificateRevoked => $this->paragraphs([
                'Your certificate for <strong>{{subject}}</strong> has been withdrawn.',
                'Reason: {{reason}}. It will no longer verify.',
            ]),

            NotificationType::SessionScheduled => $this->paragraphs([
                'You have a place on <strong>{{subject}}</strong>.',
                'It starts at {{starts_at}}. Add it to your calendar so it is not missed.',
            ]) . $this->button('View the session', '{{link}}'),

            NotificationType::SessionReminder => $this->paragraphs([
                'A reminder that <strong>{{subject}}</strong> is coming up, at {{starts_at}}.',
                'We look forward to seeing you there.',
            ]) . $this->button('View the session', '{{link}}'),

            NotificationType::SessionRescheduled => $this->paragraphs([
                '<strong>{{subject}}</strong> has moved to a new time: {{starts_at}}.',
                'Please update your calendar.',
            ]) . $this->button('View the session', '{{link}}'),

            NotificationType::SessionCancelled => $this->paragraphs([
                '<strong>{{subject}}</strong> has been cancelled.',
                'Reason: {{reason}}. Please do not travel to it.',
            ]),

            NotificationType::Announcement => $this->paragraphs([
                '{{default_body}}',
            ]),
        };

        return $this->layout($inner);
    }

    /**
     * @param list<string> $paragraphs
     */
    private function paragraphs(array $paragraphs): string
    {
        $html = '';
        foreach ($paragraphs as $p) {
            $html .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">' . $p . '</p>';
        }

        return $html;
    }

    private function button(string $label, string $href): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 4px;">'
            . '<tr><td style="border-radius:8px;background:#1f4e79;">'
            . '<a href="' . $href . '" style="display:inline-block;padding:12px 26px;'
            . 'font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">'
            . $label . '</a></td></tr></table>';
    }

    /** Wraps the per-type content in a branded, email-safe card. */
    private function layout(string $inner): string
    {
        return '<div style="background:#f1f5f9;padding:24px;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;'
            . 'border:1px solid #e2e8f0;">'
            . '<div style="background:#1f4e79;padding:20px 28px;">'
            . '<span style="color:#ffffff;font-size:18px;font-weight:700;">{{organisation}}</span>'
            . '</div>'
            . '<div style="padding:28px;">'
            . '<p style="margin:0 0 16px;font-size:15px;color:#334155;">Hi {{recipient_name}},</p>'
            . $inner
            . '</div>'
            . '<div style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;">'
            . '<p style="margin:0;font-size:12px;color:#94a3b8;">This message was sent by {{organisation}}.</p>'
            . '</div>'
            . '</div></div>';
    }
}
