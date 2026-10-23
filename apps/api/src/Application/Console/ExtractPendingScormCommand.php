<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Application\Media\ExtractScormPackage;
use Central\Api\Domain\Media\MediaRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues extraction for SCORM packages that are not yet unpacked.
 *
 * New uploads are dispatched to the worker automatically; this is for the
 * packages that predate that (or whose extraction failed and left the row with
 * no `extracted_path`). Run it once, then let the worker process the queue:
 *
 *   php bin/console scorm:extract
 *   php bin/console messenger:consume    # (already running as the worker)
 *
 * Safe to run repeatedly: a package that is already unpacked is not listed, and
 * the worker ignores a message for one that has been.
 */
#[AsCommand(
    name: 'scorm:extract',
    description: 'Queue extraction for SCORM packages not yet unpacked',
)]
final class ExtractPendingScormCommand extends Command
{
    public function __construct(
        private readonly MediaRepository $media,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $pending = $this->media->pendingScormExtractions();

        if ($pending === []) {
            $io->writeln('No SCORM packages are waiting to be unpacked.');

            return Command::SUCCESS;
        }

        foreach ($pending as $item) {
            $this->bus->dispatch(new ExtractScormPackage($item->tenantId, (string) $item->id));

            if ($output->isVerbose()) {
                $io->writeln(sprintf('  queued <info>%s</info> (%s)', $item->title, $item->id));
            }
        }

        $io->success(sprintf(
            'Queued %d SCORM package(s) for extraction. The worker will unpack them.',
            count($pending),
        ));

        return Command::SUCCESS;
    }
}
