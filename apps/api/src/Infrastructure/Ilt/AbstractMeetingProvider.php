<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Ilt;

use Central\Api\Domain\Ilt\Exception\MeetingProviderError;
use Central\Api\Domain\Ilt\MeetingProvider;
use Central\Api\Domain\Ilt\TokenGrant;
use Central\Api\Domain\Ilt\VideoIntegration;
use Central\Api\Domain\Ilt\VideoIntegrationRepository;
use Central\Api\Domain\Ilt\VideoOAuthFlow;
use Central\Api\Support\Clock;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Shared machinery for the conferencing adapters: JSON transport, OAuth token
 * refresh, and consistent error mapping.
 *
 * One adapter instance is bound to one tenant's {@see VideoIntegration}. It
 * refreshes that tenant's access token on demand and writes the new tokens
 * back through the repository — the legacy code did this too, and getting it
 * wrong is expensive: Zoom rotates the refresh token on every use, so a
 * refresh that is not persisted permanently disconnects the tenant.
 */
abstract class AbstractMeetingProvider implements MeetingProvider, VideoOAuthFlow
{
    private readonly RequestFactory $requests;
    private readonly StreamFactory $streams;

    /** Mutable view of the integration: replaced whenever tokens are refreshed. */
    private VideoIntegration $current;

    /**
     * @param array<string, mixed> $appConfig platform-level OAuth app credentials from settings
     */
    public function __construct(
        VideoIntegration $integration,
        protected readonly array $appConfig,
        private readonly VideoIntegrationRepository $integrations,
        protected readonly ClientInterface $http,
        protected readonly LoggerInterface $logger,
        protected readonly Clock $clock,
    ) {
        $this->current  = $integration;
        $this->requests = new RequestFactory();
        $this->streams  = new StreamFactory();
    }

    public function integration(): VideoIntegration
    {
        return $this->current;
    }

    /**
     * The tenant's own OAuth app if they registered one, otherwise ours.
     */
    final protected function clientId(): string
    {
        return $this->current->clientId ?? (string) ($this->appConfig['client_id'] ?? '');
    }

    final protected function clientSecret(): string
    {
        return $this->current->clientSecret ?? (string) ($this->appConfig['client_secret'] ?? '');
    }

    /**
     * A valid access token for this tenant, refreshing first if needed.
     *
     * @throws MeetingProviderError
     */
    final protected function accessToken(): string
    {
        if (!$this->current->isUsable()) {
            throw MeetingProviderError::notConnected($this->id());
        }

        if (!$this->current->needsRefresh($this->clock->now())) {
            return (string) $this->current->accessToken;
        }

        return $this->refreshAndPersist();
    }

    /**
     * @throws MeetingProviderError
     */
    private function refreshAndPersist(): string
    {
        $grant = $this->refresh((string) $this->current->refreshToken);

        $this->current = $this->current->withTokens(
            $grant->accessToken,
            $grant->refreshToken,
            $grant->expiresAt,
        );

        // Persisted immediately and outside any surrounding transaction: the
        // platform has already invalidated the previous refresh token, so if
        // this write is lost the tenant is disconnected until they reconnect
        // by hand.
        $this->integrations->saveTokens($this->current);

        $this->logger->info('Refreshed {platform} token for tenant {tenant}', [
            'platform' => $this->id()->value,
            'tenant'   => $this->current->tenantId,
        ]);

        return $grant->accessToken;
    }

    /**
     * Performs an authenticated JSON request and decodes the response.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     *
     * @return array<string, mixed> decoded body, or [] for empty/204 responses
     *
     * @throws MeetingProviderError
     */
    final protected function json(
        string $method,
        string $url,
        ?array $body = null,
        array $headers = [],
        bool $allowRetryOnUnauthorized = true,
    ): array {
        $request = $this->requests->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->accessToken());

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($encoded));
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new MeetingProviderError(
                $this->id(),
                sprintf('Could not reach %s: %s', $this->id()->label(), $e->getMessage()),
                null,
                true,
                $e,
            );
        }

        $status  = $response->getStatusCode();
        $payload = (string) $response->getBody();

        // A rejected token gets exactly one retry with a freshly refreshed one.
        if ($status === 401 && $allowRetryOnUnauthorized) {
            $this->logger->notice('{platform} rejected the access token; refreshing', [
                'platform' => $this->id()->value,
                'tenant'   => $this->current->tenantId,
            ]);

            $this->refreshAndPersist();

            return $this->json($method, $url, $body, $headers, allowRetryOnUnauthorized: false);
        }

        if ($status >= 400) {
            throw MeetingProviderError::fromHttp($this->id(), $status, $payload);
        }

        return $this->decode($payload, $status);
    }

    /**
     * Unauthenticated form POST, used by the OAuth token endpoints.
     *
     * @param array<string, string> $form
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     *
     * @throws MeetingProviderError
     */
    final protected function form(string $url, array $form, array $headers = []): array
    {
        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streams->createStream(http_build_query($form)));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new MeetingProviderError(
                $this->id(),
                sprintf('Could not reach the %s token endpoint: %s', $this->id()->label(), $e->getMessage()),
                null,
                true,
                $e,
            );
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        if ($status >= 400) {
            // A refused refresh token means the tenant must reconnect; saying
            // so plainly saves a support round-trip.
            if ($status === 400 || $status === 401) {
                throw new MeetingProviderError(
                    $this->id(),
                    sprintf(
                        '%s rejected the stored credentials. The tenant needs to reconnect the integration.',
                        $this->id()->label(),
                    ),
                    $status,
                    false,
                );
            }

            throw MeetingProviderError::fromHttp($this->id(), $status, $body);
        }

        return $this->decode($body, $status);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MeetingProviderError
     */
    private function decode(string $payload, int $status): array
    {
        if ($status === 204 || trim($payload) === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MeetingProviderError(
                $this->id(),
                sprintf('%s returned a non-JSON response.', $this->id()->label()),
                $status,
                false,
                $e,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Shared shape of the OAuth grant exchanges.
     *
     * @param array<string, string> $form
     * @param array<string, string> $headers
     *
     * @throws MeetingProviderError
     */
    final protected function grant(string $tokenUrl, array $form, array $headers = []): TokenGrant
    {
        $response = $this->form($tokenUrl, $form, $headers);

        try {
            return TokenGrant::fromResponse($response, $this->clock->now());
        } catch (\InvalidArgumentException $e) {
            throw new MeetingProviderError(
                $this->id(),
                sprintf('%s returned no access token.', $this->id()->label()),
                null,
                false,
                $e,
            );
        }
    }

    /**
     * Treats "already gone" as success. Used by every cancel() implementation.
     *
     * @param list<int> $absentStatuses
     *
     * @throws MeetingProviderError
     */
    final protected function deleteIgnoringMissing(string $url, array $absentStatuses = [404]): void
    {
        try {
            $this->json('DELETE', $url);
        } catch (MeetingProviderError $e) {
            $status = $e->details()['httpStatus'] ?? null;

            if (is_int($status) && in_array($status, $absentStatuses, true)) {
                $this->logger->info('{platform} meeting was already gone', [
                    'platform' => $this->id()->value,
                ]);

                return;
            }

            throw $e;
        }
    }

    /**
     * Lenient timestamp parsing. Platforms return RFC 3339, but a malformed or
     * absent value must not take down an attendance sync of 200 participants.
     */
    final protected function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Collapses absent, empty and non-scalar values to null, so optional
     * response fields do not become the string "" or "Array".
     */
    final protected function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * @param list<int> $absentStatuses
     *
     * @return array<string, mixed>|null
     *
     * @throws MeetingProviderError
     */
    final protected function getOrNull(string $url, array $absentStatuses = [404]): ?array
    {
        try {
            return $this->json('GET', $url);
        } catch (MeetingProviderError $e) {
            $status = $e->details()['httpStatus'] ?? null;

            if (is_int($status) && in_array($status, $absentStatuses, true)) {
                return null;
            }

            throw $e;
        }
    }
}
