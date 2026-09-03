<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Library;

use Central\Api\Application\Http\ByteRangeStream;
use Central\Api\Domain\Exception\AuthenticationFailed;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Library\DigitalLibraryItem;
use Central\Api\Domain\Library\DigitalLibraryRepository;
use Central\Api\Domain\Media\MediaKind;
use Central\Api\Infrastructure\Security\JwtTokenService;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * The bytes of a library item — inline only, never a download.
 *
 * GET /v1/library/{id}/file
 *
 * The requirement is that library content is viewable but never downloadable.
 * That is enforced here and cannot be bypassed by a query parameter: the
 * disposition is always `inline`, there is no `?download=1` branch, and no
 * other route serves these bytes. A determined viewer can still capture what
 * their own browser renders — that is true of anything shown on a screen and is
 * not something a server can prevent — but nothing here hands them the file.
 *
 * Authorised by a ticket, like the media stream and for the same reason: an
 * `<img>`, a `<video>` seek or a PDF viewer sends no Authorization header. The
 * ticket is minted by the viewer's `…/ticket` endpoint, which checks that the
 * item is actually shared with them; it names one item and lasts fifteen
 * minutes. An Office document is served as the PDF it was converted to, so what
 * leaves here is always something the browser renders inline.
 */
final class StreamLibraryAction
{
    public function __construct(
        private readonly DigitalLibraryRepository $library,
        private readonly MediaStorage $storage,
        private readonly JwtTokenService $tokens,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $item = $this->authorise($request, $args['id']);

        $key = $item->viewableKey();
        if ($key === null || !$this->storage->has($key)) {
            // A document still converting has nothing to show yet; a row whose
            // file is gone has nothing to show ever. Either way, not zero bytes
            // dressed as a success.
            throw new EntityNotFound('Library file', (string) $item->id);
        }

        $stream = new Stream($this->storage->read($key));
        $size   = $stream->getSize() ?? $item->fileSize;

        $response = $response
            ->withHeader('Content-Type', $this->mimeType($item))
            ->withHeader('Accept-Ranges', 'bytes')
            // Private: a shared proxy must not hold one tenant's file and hand
            // it to another.
            ->withHeader('Cache-Control', 'private, max-age=3600')
            // Always inline. This is the no-download guarantee, in one header.
            ->withHeader('Content-Disposition', 'inline')
            // Belt and braces against a proxy or extension offering a save.
            ->withHeader('X-Content-Type-Options', 'nosniff');

        $range = $this->range($request->getHeaderLine('Range'), $size);

        if ($range === null) {
            return $response
                ->withHeader('Content-Length', (string) $size)
                ->withBody($stream);
        }

        if ($range === false) {
            $stream->close();

            return $response
                ->withStatus(416)
                ->withHeader('Content-Range', sprintf('bytes */%d', $size));
        }

        [$start, $end] = $range;

        return $response
            ->withStatus(206)
            ->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size))
            ->withHeader('Content-Length', (string) ($end - $start + 1))
            ->withBody(new ByteRangeStream($stream, $start, $end - $start + 1));
    }

    /** The type of the bytes actually served — a converted document is a PDF. */
    private function mimeType(DigitalLibraryItem $item): string
    {
        return $item->kind === MediaKind::Document ? 'application/pdf' : $item->mimeType;
    }

    /**
     * The item this ticket is for, or an authentication failure.
     *
     * @throws AuthenticationFailed
     * @throws EntityNotFound
     */
    private function authorise(ServerRequestInterface $request, string $id): DigitalLibraryItem
    {
        $ticket = (string) ($request->getQueryParams()['ticket'] ?? '');

        if ($ticket === '') {
            throw new AuthenticationFailed(reason: 'missing_media_ticket');
        }

        $claims = $this->tokens->verifyMediaTicket($ticket, $id);

        $tenantId = $claims['tid'] ?? null;
        if (!is_string($tenantId) || $tenantId === '') {
            throw new AuthenticationFailed(reason: 'ticket_without_tenant');
        }

        return $this->library->find($tenantId, $id)
            ?? throw new EntityNotFound('Library item', $id);
    }

    /**
     * The requested byte range, clamped to the file. Null for the whole file,
     * false for a range that cannot be satisfied.
     *
     * @return array{0: int, 1: int}|false|null
     */
    private function range(string $header, int $size): array|false|null
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }

        if ($size <= 0 || preg_match('/^bytes=(\d*)-(\d*)$/', $header, $m) !== 1) {
            return null;
        }

        [, $from, $to] = $m;
        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            $length = min((int) $to, $size);

            return $length === 0 ? false : [$size - $length, $size - 1];
        }

        $start = (int) $from;
        if ($start >= $size) {
            return false;
        }

        $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);

        return $end < $start ? false : [$start, $end];
    }
}
