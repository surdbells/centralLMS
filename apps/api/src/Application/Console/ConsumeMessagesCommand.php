<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\StopWorkerOnFailureLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;

/**
 * Runs the async queue worker.
 *
 * Deliberately *not* a daemon that runs forever. Long-lived PHP processes leak
 * — Doctrine's identity map alone will grow without bound — so cron starts a
 * worker with a time limit and lets it exit cleanly:
 *
 *   * * * * * cd /path/to/api && php bin/console messenger:consume --time-limit=290
 *
 * Overlapping runs are harmless: Redis streams hand each message to exactly
 * one consumer.
 */
#[AsCommand(
    name: 'messenger:consume',
    description: 'Process queued jobs (emails, SMS, push, meeting sync) until a limit is reached',
)]
final class ConsumeMessagesCommand extends Command
{
    /**
     * The transport is resolved inside execute(), not injected.
     *
     * symfony/redis-messenger talks to Redis through ext-redis or Relay — it
     * does not accept predis — so constructing the transport fails outright on
     * a machine without the extension. Building it eagerly would make every
     * unrelated command (`list`, `doctrine:migrations:migrate`) fail there too.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('time-limit', 't', InputOption::VALUE_REQUIRED, 'Stop after this many seconds', '290')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this many messages')
            ->addOption('memory-limit', 'm', InputOption::VALUE_REQUIRED, 'Stop when memory usage exceeds this', '128M')
            ->addOption('failure-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many failures', '10')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when the queue is empty', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dispatcher = new EventDispatcher();

        $timeLimit = (int) $input->getOption('time-limit');
        if ($timeLimit > 0) {
            $dispatcher->addSubscriber(new StopWorkerOnTimeLimitListener($timeLimit, $this->logger));
        }

        $messageLimit = $input->getOption('limit');
        if ($messageLimit !== null && (int) $messageLimit > 0) {
            $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener((int) $messageLimit, $this->logger));
        }

        $dispatcher->addSubscriber(new StopWorkerOnMemoryLimitListener(
            $this->parseMemoryLimit((string) $input->getOption('memory-limit')),
            $this->logger,
        ));

        $failureLimit = (int) $input->getOption('failure-limit');
        if ($failureLimit > 0) {
            $dispatcher->addSubscriber(new StopWorkerOnFailureLimitListener($failureLimit, $this->logger));
        }

        $io->writeln(sprintf(
            '<info>Consuming</info> async queue (time limit %ds, memory limit %s)',
            $timeLimit,
            $input->getOption('memory-limit'),
        ));

        $transport = $this->container->get(TransportInterface::class);
        $bus       = $this->container->get(MessageBusInterface::class);

        $worker = new Worker(['async' => $transport], $bus, $dispatcher, $this->logger);
        $worker->run(['sleep' => max(0, (int) $input->getOption('sleep')) * 1_000_000]);

        $io->success('Worker stopped cleanly.');

        return Command::SUCCESS;
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);

        if (preg_match('/^(\d+)\s*([KMG])?B?$/i', $limit, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf('Could not parse memory limit "%s".', $limit));
        }

        $bytes = (int) $matches[1];

        return match (strtoupper($matches[2] ?? '')) {
            'K'     => $bytes * 1024,
            'M'     => $bytes * 1024 * 1024,
            'G'     => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }
}
