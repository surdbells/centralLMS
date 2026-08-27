<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Domain\Certificate\DefaultCertificateTemplate;
use Central\Api\Infrastructure\Security\PasswordHasher;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a tenant and its first administrator.
 *
 * The bootstrap problem every multi-tenant system has: a tenant admin is
 * created through the admin UI, which requires a tenant admin. This is how the
 * first one exists.
 *
 *   php bin/console tenant:provision --name="Acme" --code=acme \
 *       --email=admin@acme.test --password=…
 *
 * Omit `--password` and one is generated and printed once.
 */
#[AsCommand(
    name: 'tenant:provision',
    description: 'Create a tenant and its first administrator',
)]
final class ProvisionTenantCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Organisation name')
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'Short unique code, e.g. acme')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, "Administrator's email")
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password (generated if omitted)')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'Administrator first name', 'Admin')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Administrator last name', 'User')
            ->addOption(
                'super-admin',
                null,
                InputOption::VALUE_NONE,
                'Grant super admin (cross-tenant access) instead of tenant admin',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = (string) $input->getOption('name');
        $code = strtolower(trim((string) $input->getOption('code')));
        $email = strtolower(trim((string) $input->getOption('email')));

        foreach (['name' => $name, 'code' => $code, 'email' => $email] as $option => $value) {
            if ($value === '') {
                $io->error(sprintf('--%s is required.', $option));

                return Command::INVALID;
            }
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error('--email is not a valid email address.');

            return Command::INVALID;
        }

        $password = (string) ($input->getOption('password') ?? '');
        $generated = $password === '';

        if ($generated) {
            // 24 bytes base64 → well above anything guessable, and short
            // enough to stay under bcrypt's 72-byte limit.
            $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        }

        $connection = $this->container->get(Connection::class);
        $hasher = $this->container->get(PasswordHasher::class);

        $role = $input->getOption('super-admin') ? SystemRole::SuperAdmin : SystemRole::TenantAdmin;

        try {
            $result = $connection->transactional(
                function (Connection $c) use ($name, $code, $email, $password, $hasher, $input, $role): array {
                    $tenantId = $c->fetchOne(
                        'INSERT INTO tenants (name, code, status, contact_email, provisioned_at)
                         VALUES (:name, :code, \'active\', :email, NOW())
                         RETURNING id',
                        ['name' => $name, 'code' => $code, 'email' => $email],
                    );

                    $userId = $c->fetchOne(
                        'INSERT INTO users (tenant_id, email, password_hash, first_name, last_name, status)
                         VALUES (:tenant, :email, :hash, :first, :last, \'active\')
                         RETURNING id',
                        [
                            'tenant' => $tenantId,
                            'email'  => $email,
                            'hash'   => $hasher->hash($password),
                            'first'  => (string) $input->getOption('first-name'),
                            'last'   => (string) $input->getOption('last-name'),
                        ],
                    );

                    $roleId = $c->fetchOne(
                        'SELECT id FROM roles WHERE key = :key AND tenant_id IS NULL',
                        ['key' => $role->value],
                    );

                    if ($roleId === false) {
                        throw new \RuntimeException(
                            sprintf('Built-in role "%s" is missing. Has the identity migration run?', $role->value),
                        );
                    }

                    $c->executeStatement(
                        'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)',
                        ['user' => $userId, 'role' => $roleId],
                    );

                    // Record who administers the tenant.
                    $c->executeStatement(
                        'UPDATE tenants SET admin_user_id = :user WHERE id = :tenant',
                        ['user' => $userId, 'tenant' => $tenantId],
                    );

                    // Something to print certificates on from day one.
                    // Without a default template `findDefaultTemplate()`
                    // returns null and every certificate this tenant issues
                    // raises `template_missing` when somebody tries to open
                    // it — the register would hand out documents nobody,
                    // the holder included, could ever see.
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
        } catch (\Throwable $e) {
            // The unique indexes make a duplicate code or email a normal
            // outcome, not a crash.
            if (str_contains($e->getMessage(), 'duplicate key')) {
                $io->error('A tenant with that code, or a user with that email, already exists.');

                return Command::FAILURE;
            }

            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Provisioned "%s".', $name));
        $io->definitionList(
            ['Tenant' => $result['tenantId']],
            ['Code' => $code],
            ['Admin user' => $result['userId']],
            ['Email' => $email],
            ['Role' => $role->label()],
        );

        if ($generated) {
            $io->warning('Generated password — shown once, not recoverable:');
            $io->writeln('  ' . $password);
            $io->newLine();
        }

        return Command::SUCCESS;
    }
}
