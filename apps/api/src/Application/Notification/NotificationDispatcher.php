<?php

declare(strict_types=1);

namespace Central\Api\Application\Notification;

use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationRepository;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\TransportResult;
use Central\Api\Support\Clock;
use Psr\Log\LoggerInterface;

/**
 * Sends what is due.
 *
 * The piece the legacy never wrote. Four queue and reminder tables existed,
 * every row in all of them still says `pending`, and the reason is simply that
 * nothing ever read them.
 *
 * Claim, send, record. The claim is what makes it safe to run more than one
 * worker, which is not a theoretical concern: the compose file already starts
 * a worker container and restarts it every five minutes, so two overlap on
 * every restart. Without an atomic claim that is a duplicate email every five
 * minutes.
 */
final readonly class NotificationDispatcher
{
    /**
     * @param array<string, NotificationTransport> $transports keyed by channel value
     */
    public function __construct(
        private NotificationRepository $repository,
        private array $transports,
        private Clock $clock,
        private LoggerInterface $logger,
        /**
         * How long a claim may be held before it is assumed dead. Generous
         * relative to any real send, because releasing a delivery that is
         * still in flight sends it twice — the failure mode worth avoiding.
         */
        private int $staleClaimSeconds = 600,
    ) {
    }

    /**
     * One pass. Returns what happened, for the command to print.
     *
     * @return array{claimed: int, sent: int, failed: int, retrying: int, skipped: int, released: int}
     */
    public function dispatch(string $workerId, int $limit = 50): array
    {
        $now = $this->clock->now();

        $released = $this->repository->releaseStale(
            $now->modify(sprintf('-%d seconds', $this->staleClaimSeconds)),
        );

        if ($released > 0) {
            $this->logger->warning('Released deliveries from a worker that did not finish', [
                'count' => $released,
            ]);
        }

        $claimed = $this->repository->claimDue($workerId, $now, $limit);

        $tally = [
            'claimed'  => count($claimed),
            'sent'     => 0,
            'failed'   => 0,
            'retrying' => 0,
            'skipped'  => 0,
            'released' => $released,
        ];

        foreach ($claimed as $delivery) {
            $outcome = $this->send($delivery);
            $tally[$outcome]++;
        }

        return $tally;
    }

    /**
     * @return 'sent'|'failed'|'retrying'|'skipped'
     */
    private function send(PendingDelivery $delivery): string
    {
        $transport = $this->transports[$delivery->channel->value] ?? null;
        $at        = $this->clock->now();

        if ($transport === null) {
            // A channel with no transport is a wiring mistake, not a delivery
            // problem, and retrying it three times will not find one.
            $this->repository->markAttemptFailed(
                $delivery->deliveryId,
                $at,
                sprintf('No transport is wired for the %s channel.', $delivery->channel->label()),
            );

            $this->logger->error('A delivery has no transport', ['channel' => $delivery->channel->value]);

            return 'failed';
        }

        if (!$transport->isConfigured()) {
            // Also not worth retrying: an absent API key does not appear on
            // its own between attempts.
            $this->repository->markAttemptFailed(
                $delivery->deliveryId,
                $at,
                sprintf('%s is not configured on this deployment.', $delivery->channel->label()),
            );

            return 'skipped';
        }

        try {
            $result = $transport->send($delivery);
        } catch (\Throwable $e) {
            // A transport that throws is breaking its contract, but the batch
            // must survive it.
            $this->logger->error('A transport threw instead of returning a result', [
                'channel' => $delivery->channel->value,
                'error'   => $e->getMessage(),
            ]);

            $result = TransportResult::failed($e->getMessage());
        }

        if ($result->sent) {
            $this->repository->markSent($delivery->deliveryId, $at, $result->providerMessageId);

            return 'sent';
        }

        $error = $result->error ?? 'The provider did not say why.';

        if (!$result->retryable) {
            // Burn the remaining attempts: the row lands on `failed` now
            // rather than after two more pointless tries.
            $this->repository->markAttemptFailed(
                $delivery->deliveryId,
                $at,
                $error,
                exhaust: true,
            );

            return 'failed';
        }

        $this->repository->markAttemptFailed($delivery->deliveryId, $at, $error);

        // A retryable failure with attempts left is not yet a failure — it is
        // scheduled to go again. Counting it as one would make a working queue
        // riding out a provider blip look like a broken one.
        return $delivery->isLastAttempt() ? 'failed' : 'retrying';
    }

    /**
     * Which channels this deployment can actually send on.
     *
     * Surfaced by the health endpoint: a deployment silently missing its mail
     * credentials looks identical to one where nobody has done anything yet.
     *
     * @return array<string, bool>
     */
    public function configuredChannels(): array
    {
        $status = [];

        foreach (NotificationChannel::cases() as $channel) {
            $transport = $this->transports[$channel->value] ?? null;

            $status[$channel->value] = $transport !== null && $transport->isConfigured();
        }

        return $status;
    }
}
