<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

interface EmailTemplateRepository
{
    /**
     * Everything one organisation has written its own wording for.
     *
     * @return array<string, EmailTemplate> keyed by NotificationType::value
     */
    public function forTenant(string $tenantId): array;

    public function find(string $tenantId, NotificationType $type): ?EmailTemplate;

    public function save(EmailTemplate $template): EmailTemplate;

    /** Puts one back to the platform default by removing the override. */
    public function delete(string $tenantId, NotificationType $type): bool;
}
