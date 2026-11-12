<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Settings;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Notification\EmailBrandingResolver;
use Central\Api\Application\Notification\EmailComposer;
use Central\Api\Application\Notification\EmailSender;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Notification\EmailTemplate;
use Central\Api\Domain\Notification\EmailTemplateDefaults;
use Central\Api\Domain\Notification\EmailTemplateRenderer;
use Central\Api\Domain\Notification\EmailTemplateRepository;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationComposer;
use Central\Api\Domain\Notification\NotificationPriority;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Notification\PendingDelivery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * What an organisation's own mail says.
 *
 * GET    /v1/email-templates          every kind of message, with its wording
 * GET    /v1/email-templates/{type}   one, with a preview
 * PUT    /v1/email-templates/{type}   write your own
 * DELETE /v1/email-templates/{type}   go back to the default
 * POST   /v1/email-templates/{type}/preview  render unsaved wording, branded
 * POST   /v1/email-templates/{type}/test     send the (unsaved) wording to yourself
 *
 * Every preview is the real email: the same composer the mail worker uses,
 * with this organisation's logo, colour and name, around example facts.
 *
 * The default wording lives in {@see NotificationComposer} — one set of
 * English sentences for the whole platform. Fine as a default; wrong as the
 * only option for a company with its own tone, its own signature, or its own
 * language.
 *
 * Not every notification is worth an email, so the list is the ones that
 * actually go out by mail. A screen offering to rewrite the wording of
 * something delivered only to the bell is offering something that does
 * nothing.
 */
final class ManageEmailTemplatesAction
{
    public function __construct(
        private readonly EmailTemplateRepository $templates,
        private readonly NotificationComposer $composer,
        private readonly EmailTemplateRenderer $renderer,
        private readonly EmailTemplateDefaults $richDefaults,
        private readonly EmailComposer $emails,
        private readonly EmailBrandingResolver $branding,
        private readonly EmailSender $sender,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request);
        $written  = $this->templates->forTenant($tenantId);

        $all = [];

        foreach ($this->emailable() as $type) {
            $template = $written[$type->value] ?? null;

            $all[] = [
                'type'      => $type->value,
                'label'     => $type->label(),
                // Section of the list, and when it is sent, in one line.
                'group'       => $type->emailGroup(),
                'description' => $type->emailDescription(),
                // What it says today, so the list reads as the mail somebody
                // would actually receive rather than a list of empty slots.
                'usesDefault' => $template === null || !$template->overrides(),
                // Whether the organisation has its own wording saved at all,
                // paused or not. usesDefault says what is sent; this says
                // whether there is anything to reset.
                'customised'  => $this->isCustomised($template),
                'subject'   => $template?->subject,
                'body'      => $template?->body,
                'enabled'   => $template === null ? true : $template->enabled,
                'updatedAt' => $template?->updatedAt?->format(\DateTimeInterface::ATOM),
                'default'   => $this->defaultWording($type),
            ];
        }

        return Json::ok($response, [
            'templates'    => $all,
            // The list a template may refer to. Sent with the templates so the
            // editor can offer them rather than the author guessing.
            'placeholders' => EmailTemplateRenderer::PLACEHOLDERS,
            // The look every email carries, for the strip above the list.
            'branding'     => $this->branding->summary($tenantId),
            'groups'       => ['account', 'enrolment', 'certificates', 'sessions', 'announcements'],
        ]);
    }

    /**
     * One template, with a preview of what it would send.
     *
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $type     = $this->type($args);
        $template = $this->templates->find($tenantId, $type);

        return Json::ok($response, [
            'type'         => $type->value,
            'label'        => $type->label(),
            'group'        => $type->emailGroup(),
            'description'  => $type->emailDescription(),
            'usesDefault'  => $template === null || !$template->overrides(),
            'customised'   => $this->isCustomised($template),
            'subject'      => $template?->subject,
            'body'         => $template?->body,
            'enabled'      => $template === null ? true : $template->enabled,
            'default'      => $this->defaultWording($type),
            'preview'      => $this->preview($tenantId, $type, $template),
            'placeholders' => EmailTemplateRenderer::PLACEHOLDERS,
            'branding'     => $this->branding->summary($tenantId),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function save(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $type     = $this->type($args);
        $body     = $this->body($request);

        $subject = $this->wording($body, 'subject');
        $content = $this->wording($body, 'body');

        // Caught here rather than in somebody's inbox. An unknown placeholder
        // renders as nothing, so without this a typo means a sentence with a
        // hole in it going out to the whole organisation.
        $violations = $this->placeholderProblems($subject, $content);

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $saved = $this->templates->save(new EmailTemplate(
            id: '',
            tenantId: $tenantId,
            type: $type,
            subject: $subject,
            body: $content,
            enabled: filter_var($body['enabled'] ?? true, FILTER_VALIDATE_BOOL),
            updatedBy: AuthenticationMiddleware::require($request)->id,
        ));

        return Json::ok($response, [
            ...$saved->toArray(),
            'usesDefault' => !$saved->overrides(),
            'customised'  => $this->isCustomised($saved),
            'default' => $this->defaultWording($type),
            'preview' => $this->preview($tenantId, $type, $saved),
        ]);
    }

    /**
     * The branded email for wording that has not been saved yet — what the
     * editor shows as somebody types. Body {subject?, body?}; either left out
     * or empty means the default. Unknown placeholders are reported, not
     * refused, so the preview keeps working mid-edit.
     *
     * @param array<string, string> $args
     */
    public function previewDraft(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $type     = $this->type($args);
        $draft    = $this->draft($tenantId, $type, $this->body($request));
        $email    = $this->emails->compose($tenantId, $type, $this->example($tenantId), $draft);

        return Json::ok($response, [
            'subject'  => $email['subject'],
            'html'     => $email['html'],
            'text'     => $email['text'],
            'problems' => (object) $this->placeholderProblems($draft->subject, $draft->body),
        ]);
    }

    /**
     * Sends the email — the draft in the body, or what is saved — to the
     * person asking, with example facts, so they can see it in their own
     * inbox before anyone else does.
     *
     * @param array<string, string> $args
     */
    public function test(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $type     = $this->type($args);
        $caller   = AuthenticationMiddleware::require($request);
        $body     = $this->body($request);

        $template = array_key_exists('subject', $body) || array_key_exists('body', $body)
            ? $this->draft($tenantId, $type, $body)
            : $this->templates->find($tenantId, $type);

        $problems = $template === null ? [] : $this->placeholderProblems($template->subject, $template->body);

        if ($problems !== []) {
            throw ValidationFailed::fromArray($problems);
        }

        if (!$this->sender->isConfigured()) {
            throw new ConflictingState('Outgoing email is not set up on this server.', 'email_not_configured');
        }

        $email = $this->emails->compose($tenantId, $type, $this->example($tenantId), $template);

        $result = $this->sender->send(new PendingDelivery(
            deliveryId: Uuid::uuid4()->toString(),
            tenantId: $tenantId,
            notificationId: Uuid::uuid4()->toString(),
            userId: $caller->id,
            channel: NotificationChannel::Email,
            address: $caller->email,
            type: $type,
            title: '[Test] ' . $email['subject'],
            body: $email['html'],
            // Already composed and branded: sent as it is.
            data: ['emailLayout' => EmailComposer::PREBUILT, 'textBody' => $email['text']],
            priority: NotificationPriority::Normal,
            attempts: 0,
            maxAttempts: 1,
        ));

        if (!$result->sent) {
            throw new ConflictingState(
                'The test email could not be sent: ' . ($result->error ?? 'unknown error'),
                'email_failed',
            );
        }

        return Json::ok($response, ['sentTo' => $caller->email, 'subject' => $email['subject']]);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->templates->delete($this->tenantId($request), $this->type($args));

        return Json::noContent($response);
    }

    // --- internals --------------------------------------------------------

    private function isCustomised(?EmailTemplate $template): bool
    {
        return $template !== null && ($template->subject !== null || $template->body !== null);
    }

    /**
     * The types that actually go out by email.
     *
     * @return list<NotificationType>
     */
    private function emailable(): array
    {
        $types = [];

        foreach (NotificationType::cases() as $type) {
            if (in_array(NotificationChannel::Email, $type->defaultChannels(), true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * The platform wording, filled with example facts.
     *
     * Shown beside the editor so an author can see what they are replacing.
     *
     * @return array{title: string, body: string}
     */
    private function defaultWording(NotificationType $type): array
    {
        $composed = $this->composer->compose($type, $this->example());

        // The subject stays the composer's plain line; the body is the rich,
        // laid-out HTML an author starts from. Placeholders are left in so the
        // default reads as an editable template, not one person's filled-in copy.
        return [
            'title' => $composed['title'],
            'body'  => $this->richDefaults->body($type),
        ];
    }

    /**
     * What this template would send, with the same example facts: the whole
     * branded email, exactly as the mail worker would compose it.
     *
     * `title`/`body` are kept for older screens; `subject`/`html`/`text` are
     * the same email under clearer names.
     *
     * @return array{title: string, body: string, subject: string, html: string, text: string}
     */
    private function preview(
        string $tenantId,
        NotificationType $type,
        ?EmailTemplate $template,
    ): array {
        $email = $this->emails->compose($tenantId, $type, $this->example($tenantId), $template);

        return [
            'title'   => $email['subject'],
            'body'    => $email['html'],
            'subject' => $email['subject'],
            'html'    => $email['html'],
            'text'    => $email['text'],
        ];
    }

    /**
     * Unsaved wording, as a template the composer can render.
     *
     * @param array<string, mixed> $body
     */
    private function draft(string $tenantId, NotificationType $type, array $body): EmailTemplate
    {
        return new EmailTemplate(
            id: '',
            tenantId: $tenantId,
            type: $type,
            subject: $this->wording($body, 'subject') ?? $this->wording($body, 'title'),
            body: $this->wording($body, 'body'),
        );
    }

    /**
     * Placeholders the wording uses that do not exist, by field.
     *
     * @return array<string, string>
     */
    private function placeholderProblems(?string $subject, ?string $content): array
    {
        $violations = [];

        foreach (['subject' => $subject, 'body' => $content] as $field => $text) {
            if ($text === null) {
                continue;
            }

            $unknown = $this->renderer->unknownPlaceholders($text);

            if ($unknown !== []) {
                $violations[$field] = sprintf(
                    'This does not recognise %s. Use one of: %s.',
                    implode(', ', array_map(static fn (string $n): string => '{{' . $n . '}}', $unknown)),
                    implode(', ', EmailTemplateRenderer::PLACEHOLDERS),
                );
            }
        }

        return $violations;
    }

    /**
     * Stand-in facts for a preview.
     *
     * Every key any type's wording reads, so one array serves all of them and
     * a preview never shows an empty sentence for want of a field.
     *
     * @return array<string, mixed>
     */
    private function example(?string $tenantId = null): array
    {
        $site = $this->branding->webUrl();

        return [
            'recipientName'     => 'Ada Lovelace',
            'subjectTitle'      => 'Fire Safety Essentials',
            'title'             => 'Office closed on Friday',
            'body'              => "The office will be closed on Friday for maintenance.\n"
                . 'All live sessions that day will run online as normal.',
            'dueDate'           => '30 September 2026',
            'startsAt'          => 'Wed 30 Sep 2026, 09:00 WAT',
            'offsetMinutes'     => 60,
            'organisation'      => $tenantId === null
                ? 'your organisation'
                : $this->branding->forTenant($tenantId)->organisation,
            'certificateNumber' => 'CERT-2026-00042',
            'percentage'        => 88,
            'passed'            => true,
            'reason'            => 'the trainer is unavailable',
            'actorName'         => 'Grace Hopper',
            'snippet'           => 'Great question — the answer is in module 3.',
            'link'              => ($site !== '' ? $site : 'https://example.com') . '/home',
            'expiresIn'         => '24 hours',
        ];
    }

    /**
     * Null keeps the default; an empty string is somebody deliberately
     * clearing what they had written.
     *
     * @param array<string, mixed> $body
     */
    private function wording(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body)) {
            return null;
        }

        $value = trim((string) $body[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, string> $args
     */
    private function type(array $args): NotificationType
    {
        $type = NotificationType::tryFrom($args['type'] ?? '');

        if ($type === null) {
            throw new EntityNotFound('Notification type', $args['type'] ?? '');
        }

        return $type;
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::SettingsManage);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::SettingsManage->value);
        }

        return $caller->tenantId;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
