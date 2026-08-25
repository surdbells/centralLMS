<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Infrastructure\Ilt\MeetingProviderFactory;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reports which conferencing platforms this deployment can offer.
 *
 * Deployment-level, not tenant-level: it checks that our OAuth app credentials
 * are present for each platform. Whether a given customer has connected their
 * account is a separate question, answered by `--tenant`.
 *
 * Worth running after any credential change and in staging after every deploy.
 */
#[AsCommand(
    name: 'ilt:check-providers',
    description: 'Report which meeting platforms are configured, and optionally a tenant\'s connections',
)]
final class CheckIltProvidersCommand extends Command
{
    /**
     * The container rather than the factory: the factory needs the credentials
     * encryption key, and `bin/console list` must work on a box where ILT was
     * never configured.
     */
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'tenant',
            't',
            InputOption::VALUE_REQUIRED,
            'Also show whether this tenant has connected each platform',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var MeetingProviderFactory $providers */
        $providers = $this->container->get(MeetingProviderFactory::class);

        $rows      = [];
        $available = 0;

        foreach (MeetingProviderId::cases() as $platform) {
            $ok = $providers->isPlatformAvailable($platform);
            $available += $ok ? 1 : 0;

            $rows[] = [
                $platform->label(),
                $platform->value,
                $ok ? '<info>configured</info>' : '<comment>no OAuth app</comment>',
                $platform->supportsCloudRecording() ? 'yes' : 'no',
                $platform->supportsWaitingRoom() ? 'yes' : 'no',
            ];
        }

        $io->section('Deployment');
        $io->table(['Platform', 'Id', 'OAuth app', 'Cloud recording', 'Waiting room'], $rows);

        $tenantId = $input->getOption('tenant');

        if (is_string($tenantId) && $tenantId !== '') {
            $io->section(sprintf('Tenant %s', $tenantId));

            $connections = $providers->describeForTenant($tenantId);

            if ($connections === []) {
                $io->warning('This tenant has connected no conferencing platform.');
            } else {
                $io->table(
                    ['Platform', 'Connected', 'Default', 'Token expires'],
                    array_map(static fn (array $c): array => [
                        $c['label'],
                        $c['connected'] ? '<info>yes</info>' : '<error>no</error>',
                        $c['isDefault'] ? 'yes' : '',
                        $c['expiresAt'] ?? '—',
                    ], $connections),
                );
            }
        }

        if ($available === 0) {
            $io->warning(
                'No platform has OAuth app credentials, so no tenant can connect one. '
                . 'See the ILT section of .env.example.',
            );

            return Command::FAILURE;
        }

        $io->success(sprintf('%d of %d platforms available.', $available, count(MeetingProviderId::cases())));

        return Command::SUCCESS;
    }
}
