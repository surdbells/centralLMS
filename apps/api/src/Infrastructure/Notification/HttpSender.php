<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Notification;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * The small amount of PSR-18 plumbing the three provider transports share.
 *
 * Matches what AbstractMeetingProvider does for the meeting providers: PSR-18
 * `sendRequest` with PSR-17 factories, rather than a Guzzle-specific `request`
 * call, so the client stays swappable and the tests can hand over a stub
 * without pulling Guzzle into them.
 *
 * A transport error becomes a returned tuple, never an exception — sending is
 * expected to fail sometimes, and the dispatcher records it against the row.
 */
final readonly class HttpSender
{
    private RequestFactory $requests;

    private StreamFactory $streams;

    public function __construct(private ClientInterface $http)
    {
        $this->requests = new RequestFactory();
        $this->streams  = new StreamFactory();
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string> $headers
     * @return array{status: int, body: string, error: ?string}
     */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->post(
            $url,
            (string) json_encode($body, JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers,
        );
    }

    /**
     * @param  array<string, string> $fields
     * @return array{status: int, body: string, error: ?string}
     */
    public function postForm(string $url, array $fields): array
    {
        return $this->post(
            $url,
            http_build_query($fields),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
        );
    }

    /**
     * @param  array<string, string> $headers
     * @return array{status: int, body: string, error: ?string}
     */
    private function post(string $url, string $payload, array $headers): array
    {
        $request = $this->requests->createRequest('POST', $url)
            ->withBody($this->streams->createStream($payload));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // Status 0 marks "never reached the provider", which is always
            // worth another attempt — unlike a 4xx, which will not change.
            return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
        }

        return [
            'status' => $response->getStatusCode(),
            'body'   => (string) $response->getBody(),
            'error'  => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
