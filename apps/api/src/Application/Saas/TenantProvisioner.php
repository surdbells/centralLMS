<?php

declare(strict_types=1);

namespace Central\Api\Application\Saas;

use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Domain\Certificate\DefaultCertificateTemplate;
use Central\Api\Infrastructure\Security\PasswordHasher;
use Doctrine\DBAL\Connection;

/**
 * Creates an organisation and its first administrator, in one transaction.
 *
 * This is the provisioning the CLI (`tenant:provision`) has always done, lifted
 * out so the operator console and — later — the public sign-up run exactly the
 * same steps: the tenant, its admin user with the tenant-admin role, the
 * `admin_user_id` link, and a default certificate template so the register can
 * issue documents from day one. A plan, if given, sets the tenant's allowances
 * and a trial its expiry.
 *
 * All or nothing: a half-provisioned organisation — a tenant with no admin, or
 * an admin with no way to print a certificate — is worse than a clean failure.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /**
     * @return array{tenantId: string, userId: string, generatedPassword: ?string}
     */
    public function provision(
        string $name,
        string $code,
        string $contactEmail,
        string $adminFirstName,
        string $adminLastName,
        ?string $password = null,
        ?string $planId = null,
        string $status = 'active',
        bool $superAdmin = false,
    ): array {
        $generated = null;
        if ($password === null || $password === '') {
            // 18 bytes base64url — well beyond guessable, under bcrypt's limit.
            $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
            $generated = $password;
        }

        $role = $superAdmin ? SystemRole::SuperAdmin : SystemRole::TenantAdmin;
        $hash = $this->hasher->hash($password);

        $result = $this->connection->transactional(
            function (Connection $c) use ($name, $code, $contactEmail, $adminFirstName, $adminLastName, $hash, $planId, $status, $role): array {
                // The plan's allowances become the tenant's limits; a trial gets
                // its expiry from the plan's trial length.
                $plan = $planId === null ? null : $c->fetchAssociative(
                    'SELECT users_limit, storage_limit_mb, trial_days FROM plans WHERE id = :id AND deleted_at IS NULL',
                    ['id' => $planId],
                );

                $trialDays = $plan['trial_days'] ?? null;
                $expiresAt = ($status === 'trial' && $trialDays !== null)
                    ? (new \DateTimeImmutable())->modify('+' . (int) $trialDays . ' days')->format('Y-m-d')
                    : null;

                $tenantId = $c->fetchOne(
                    "INSERT INTO tenants
                         (name, code, status, contact_email, plan_id, users_limit, storage_limit_mb,
                          subscription_expires_at, provisioned_at)
                     VALUES (:name, :code, :status, :email, :plan, :users, :storage, :expires, NOW())
                     RETURNING id",
                    [
                        'name'    => $name,
                        'code'    => $code,
                        'status'  => $status,
                        'email'   => $contactEmail,
                        'plan'    => $planId,
                        'users'   => $plan['users_limit'] ?? null,
                        'storage' => $plan['storage_limit_mb'] ?? null,
                        'expires' => $expiresAt,
                    ],
                );

                $userId = $c->fetchOne(
                    "INSERT INTO users (tenant_id, email, password_hash, first_name, last_name, status)
                     VALUES (:tenant, :email, :hash, :first, :last, 'active')
                     RETURNING id",
                    [
                        'tenant' => $tenantId,
                        'email'  => $contactEmail,
                        'hash'   => $hash,
                        'first'  => $adminFirstName,
                        'last'   => $adminLastName,
                    ],
                );

                $roleId = $c->fetchOne(
                    'SELECT id FROM roles WHERE key = :key AND tenant_id IS NULL',
                    ['key' => $role->value],
                );
                if ($roleId === false) {
                    throw new \RuntimeException(sprintf('Built-in role "%s" is missing.', $role->value));
                }

                $c->executeStatement(
                    'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
                    ['user' => $userId, 'role' => $roleId],
                );

                $c->executeStatement(
                    'UPDATE tenants SET admin_user_id = :user WHERE id = :tenant',
                    ['user' => $userId, 'tenant' => $tenantId],
                );

                // Without a default template every certificate this tenant
                // issues raises `template_missing` when someone opens it.
                $template = DefaultCertificateTemplate::for((string) $tenantId);
                $c->executeStatement(
                    'INSERT INTO certificate_templates
                         (id, tenant_id, name, description, body_html, styles_css,
                          orientation, page_size, is_default, is_active)
                     VALUES (:id, :tenant, :name, :description, :body, :styles,
                             :orientation, :pageSize, true, true)',
                    [
                        'id'          => $template->id,
                        'tenant'      => $tenantId,
                        'name'        => $template->name,
                        'description' => $template->description,
                        'body'        => $template->bodyHtml,
                        'styles'      => $template->stylesCss,
                        'orientation' => $template->orientation,
                        'pageSize'    => $template->pageSize,
                    ],
                );

                return ['tenantId' => (string) $tenantId, 'userId' => (string) $userId];
            },
        );

        return [...$result, 'generatedPassword' => $generated];
    }
}
