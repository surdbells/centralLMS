<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Settings;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Notification\EmailTemplate;
use Central\Api\Domain\Notification\EmailTemplateRenderer;
use Central\Api\Domain\Notification\EmailTemplateRepository;
use Central\Api\Domain\Notification\NotificationComposer;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Tenant\TenantRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What an organisation's own mail says.
 *
 * GET    /v1/email-templates          every kind of message, with its wording
 * GET    /v1/email-templates/{type}   one, with a preview
 * PUT    /v1/email-templates/{type}   write your own
 * DELETE /v1/email-templates/{type}   go back to the default
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
        private readonly TenantRepository $tenants,
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
                // What it says today, so the list reads as the mail somebody
                // would actually receive rather than a list of empty slots.
                'usesDefault' => $template === null || !$template->overrides(),
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
            'usesDefault'  => $template === null || !$template->overrides(),
            'subject'      => $template?->subject,
            'body'         => $template?->body,
            'enabled'      => $template === null ? true : $template->enabled,
            'default'      => $this->defaultWording($type),
            'preview'      => $this->preview($tenantId, $type, $template),
            'placeholders' => EmailTemplateRenderer::PLACEHOLDERS,
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

        $violations = [];

        // Caught here rather than in somebody's inbox. An unknown placeholder
        // renders as nothing, so without this a typo means a sentence with a
        // hole in it going out to the whole organisation.
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
            'default' => $this->defaultWording($type),
            'preview' => $this->preview($tenantId, $type, $saved),
        ]);
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

    /**
     * The types that actually go out by email.
     *
     * @return list<NotificationType>
     */
    private function emailable(): array
    {
        $types = [];

        foreach (NotificationType::cases() as $type) {
            if (in_array(
                \Central\Api\Domain\Notification\NotificationChannel::Email,
                $type->defaultChannels(),
                true,
            )) {
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
        return $this->composer->compose($type, $this->example());
    }

    /**
     * What this template would send, with the same example facts.
     */
    /**
     * @return array{title: string, body: string}
     */
    private function preview(
        string $tenantId,
        NotificationType $type,
        ?EmailTemplate $template,
    ): array {
        return $this->renderer->render(
            $template,
            $this->defaultWording($type),
            $this->example(),
            $this->tenants->nameOf($tenantId),
            'Ada Lovelace',
        );
    }

    /**
     * Stand-in facts for a preview.
     *
     * Every key any type's wording reads, so one array serves all of them and
     * a preview never shows an empty sentence for want of a field.
     *
     * @return array<string, mixed>
     */
    private function example(): array
    {
        return [
            'subjectTitle'      => 'Fire Safety',
            'title'             => 'Fire Safety',
            'body'              => 'An example announcement.',
            'dueDate'           => '30 September 2026',
            'startsAt'          => '2026-09-30T09:00:00+00:00',
            'offsetMinutes'     => 60,
            'organisation'      => 'your organisation',
            'certificateNumber' => 'ACME-2026-00042',
            'percentage'        => 88,
            'reason'            => 'the room is unavailable',
            'link'              => 'https://example.com/set-your-password',
            'expiresIn'         => 'one hour',
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
