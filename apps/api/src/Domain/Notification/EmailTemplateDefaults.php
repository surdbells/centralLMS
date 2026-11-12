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
    /**
     * The rich HTML content for a type. Every type has one (the match is
     * exhaustive).
     *
     * Content only — no header, logo or footer. {@see EmailLayout} adds those
     * at send time from the organisation's branding, so the default (and any
     * template an author builds from it) follows a change of logo or colour.
     */
    public function body(NotificationType $type): string
    {
        $hi = EmailLayout::paragraph('Hi {{recipient_name}},');

        return match ($type) {
            NotificationType::Welcome => EmailLayout::heading('Welcome to {{organisation}}')
                . $hi
                . EmailLayout::paragraph('Your account is ready. Everything assigned to you is waiting on your '
                    . 'dashboard — your courses, your live sessions, your certificates and your progress, all in one place.')
                . $this->cta('Go to my dashboard'),

            NotificationType::Invitation => EmailLayout::heading('You’re invited to join {{organisation}}')
                . $hi
                . EmailLayout::paragraph('An account has been created for you on the <strong>{{organisation}}</strong> '
                    . 'learning platform. Choose a password to get started.')
                . EmailLayout::facts([
                    ['Organisation', '{{organisation}}'],
                    ['Link valid for', '{{expires_in}}', 'expires_in'],
                ])
                . $this->cta('Set my password')
                . $this->linkFallback()
                . EmailLayout::paragraph('Not expecting this? You can ignore this email and no account will be activated.', true),

            NotificationType::PasswordReset => EmailLayout::heading('Reset your password')
                . $hi
                . EmailLayout::paragraph('We received a request to reset the password on your '
                    . '<strong>{{organisation}}</strong> account. Choose a new one using the button below.')
                . EmailLayout::when('expires_in', EmailLayout::paragraph('For your security the link works for '
                    . '<strong>{{expires_in}}</strong>.'))
                . $this->cta('Reset my password')
                . $this->linkFallback()
                . EmailLayout::paragraph('If you did not ask for this, you can safely ignore this email — your '
                    . 'password has not changed.', true),

            NotificationType::EnrollmentAssigned => EmailLayout::heading('You’re enrolled on {{subject}}')
                . $hi
                . EmailLayout::paragraph('<strong>{{subject}}</strong> has been added to your learning at '
                    . '{{organisation}}. Open it whenever you are ready to begin — your progress is saved as you go.')
                . EmailLayout::facts([
                    // A course or a learning path: "Learning" fits both.
                    ['Learning', '{{subject}}'],
                    ['Complete by', '{{due_date}}', 'due_date'],
                ])
                . $this->cta('Start learning'),

            NotificationType::EnrollmentDueSoon => EmailLayout::heading('{{subject}} is due soon')
                . $hi
                . EmailLayout::paragraph('A friendly reminder that you have not finished <strong>{{subject}}</strong> '
                    . 'yet. A little time now keeps you on track.')
                . EmailLayout::facts([
                    ['Course', '{{subject}}'],
                    ['Due', '{{due_date}}', 'due_date'],
                ])
                . $this->cta('Continue learning'),

            NotificationType::EnrollmentOverdue => EmailLayout::heading('{{subject}} is overdue')
                . $hi
                . EmailLayout::paragraph('<strong>{{subject}}</strong> has passed its due date. Please complete it '
                    . 'as soon as you can.')
                . EmailLayout::facts([
                    ['Course', '{{subject}}'],
                    ['Was due', '{{due_date}}', 'due_date'],
                ])
                . $this->cta('Finish now'),

            NotificationType::CourseCompleted => EmailLayout::heading('Congratulations — you completed {{subject}}')
                . $hi
                . EmailLayout::paragraph('Well done on finishing <strong>{{subject}}</strong>. It now counts '
                    . 'towards your learning record at {{organisation}}.')
                . EmailLayout::facts([
                    ['Course', '{{subject}}'],
                    ['Score', '{{percentage}}%', 'percentage'],
                ])
                . $this->cta('See my learning'),

            NotificationType::AssessmentGraded => EmailLayout::heading('Your result for {{subject}}')
                . $hi
                . EmailLayout::paragraph('Your attempt at <strong>{{subject}}</strong> has been marked. Open it to '
                    . 'see the feedback in full.')
                . EmailLayout::facts([
                    ['Assessment', '{{subject}}'],
                    ['Score', '{{percentage}}%', 'percentage'],
                ])
                . $this->cta('See my result'),

            NotificationType::CertificateIssued => EmailLayout::heading('Your certificate is ready')
                . $hi
                . EmailLayout::paragraph('Congratulations — your certificate for <strong>{{subject}}</strong> has '
                    . 'been issued. You can download it or share it from your certificates.')
                . EmailLayout::facts([
                    ['Course', '{{subject}}'],
                    ['Certificate no.', '{{certificate_number}}', 'certificate_number'],
                ])
                . $this->cta('View my certificate'),

            NotificationType::CertificateRevoked => EmailLayout::heading('Your certificate has been withdrawn')
                . $hi
                . EmailLayout::paragraph('Your certificate for <strong>{{subject}}</strong> has been withdrawn and '
                    . 'will no longer verify.')
                . EmailLayout::facts([
                    ['Course', '{{subject}}'],
                    ['Certificate no.', '{{certificate_number}}', 'certificate_number'],
                    ['Reason', '{{reason}}', 'reason'],
                ])
                . EmailLayout::paragraph('If you think this is a mistake, please contact your administrator at '
                    . '{{organisation}}.', true),

            NotificationType::SessionScheduled => EmailLayout::heading('You have a place on {{subject}}')
                . $hi
                . EmailLayout::paragraph('You have been booked on <strong>{{subject}}</strong>. Add it to your '
                    . 'calendar so it is not missed.')
                . EmailLayout::facts([
                    ['Session', '{{subject}}'],
                    ['Starts', '{{starts_at}}', 'starts_at'],
                ])
                . $this->cta('View the session'),

            NotificationType::SessionReminder => EmailLayout::heading('{{subject}} is coming up')
                . $hi
                . EmailLayout::paragraph('A reminder that <strong>{{subject}}</strong> is coming up soon. '
                    . 'We look forward to seeing you there.')
                . EmailLayout::facts([
                    ['Session', '{{subject}}'],
                    ['Starts', '{{starts_at}}', 'starts_at'],
                ])
                . $this->cta('View the session'),

            NotificationType::SessionRescheduled => EmailLayout::heading('{{subject}} has moved')
                . $hi
                . EmailLayout::paragraph('<strong>{{subject}}</strong> has a new time. Please update your calendar.')
                . EmailLayout::facts([
                    ['Session', '{{subject}}'],
                    ['New time', '{{starts_at}}', 'starts_at'],
                ])
                . $this->cta('View the session'),

            NotificationType::SessionCancelled => EmailLayout::heading('{{subject}} has been cancelled')
                . $hi
                . EmailLayout::paragraph('<strong>{{subject}}</strong> will not go ahead. Please do not travel to it.')
                . EmailLayout::facts([
                    ['Session', '{{subject}}'],
                    ['Was due to start', '{{starts_at}}', 'starts_at'],
                    ['Reason', '{{reason}}', 'reason'],
                ])
                . $this->cta('See my live sessions'),

            // The forum sentence names who acted and quotes them; the placeholder
            // list has no actor or snippet, so it rides in on {{default_body}}.
            NotificationType::ForumReply,
            NotificationType::ForumMention => EmailLayout::heading('New activity in {{subject}}')
                . $hi
                . EmailLayout::paragraph('{{default_body}}')
                . $this->cta('Open the discussion'),

            NotificationType::Announcement => EmailLayout::heading('{{subject}}')
                . $hi
                . EmailLayout::paragraph('{{default_body}}')
                . $this->cta('Open {{organisation}}'),
        };
    }

    /**
     * The button, in the organisation's colour, shown only when there is
     * somewhere for it to go.
     */
    private function cta(string $label): string
    {
        return EmailLayout::when(
            'link',
            EmailLayout::button($label, '{{link}}', '{{brand_color}}', '{{brand_text_color}}'),
        );
    }

    /** For mail clients that strip buttons, and people who copy links. */
    private function linkFallback(): string
    {
        return EmailLayout::when('link', EmailLayout::paragraph(
            'Button not working? Copy this address into your browser:<br>'
            . '<a href="{{link}}" style="word-break:break-all;">{{link}}</a>',
            true,
        ));
    }
}
