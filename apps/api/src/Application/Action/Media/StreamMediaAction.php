<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Media;

use Central\Api\Application\Http\ByteRangeStream;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Media\MediaRepository;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * The bytes.
 *
 * GET /v1/media/{id}/file
 *
 * Everything goes through here rather than through a link to storage. The
 * legacy answered with a 302 to a signed S3 URL, which meant the check on who
 * was asking happened once, and the result was a URL that worked for anybody
 * it was forwarded to until it expired — including somebody who had since been
 * removed from the organisation.
 *
 * Range requests are answered because a video player seeking to the middle of
 * a two-hour recording sends one, and a server that ignores it makes the
 * player download the whole file to skip five minutes.
 */
final class StreamMediaAction extends MediaAction
{
    public function __construct(
        MediaRepository $media,
        private readonly MediaStorage $storage,
    ) {
        parent::__construct($media);
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::MediaView);

        $item = $this->require($request, $args['id']);

        if (!$this->storage->has($item->storageKey)) {
            // The row survived and the file did not. Saying so beats streaming
            // zero bytes as a success.
            throw new EntityNotFound('Media file', (string) $item->id);
        }

        $resource = $this->storage->read($item->storageKey);
        $stream   = new Stream($resource);
        $size     = $stream->getSize() ?? $item->fileSize;

        $response = $response
            ->withHeader('Content-Type', $item->mimeType)
            ->withHeader('Accept-Ranges', 'bytes')
            // A media library is not a public CDN: private stops a shared
            // proxy from holding one tenant's file and serving it to another.
            ->withHeader('Cache-Control', 'private, max-age=3600')
            ->withHeader('Content-Disposition', $this->disposition($request, $item->originalFilename));

        $range = $this->range($request->getHeaderLine('Range'), $size);

        if ($range === null) {
            return $response
                ->withHeader('Content-Length', (string) $size)
                ->withBody($stream);
        }

        if ($range === false) {
            $stream->close();

            // 416 rather than the whole file: a player asking for a range past
            // the end has miscounted, and sending it two hours of video it did
            // not ask for hides that.
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

    /**
     * The requested byte range, clamped to the file.
     *
     * Null for no range at all — send the whole file. False for a range that
     * cannot be satisfied, which is a 416 rather than silently the whole file.
     *
     * Only the single-range form, which is what every player sends. A
     * multipart range response is a different content type and no browser
     * needs it here.
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
            // An unparseable or multi-range header. Ignored rather than
            // refused: RFC 9110 says a recipient that cannot understand a
            // Range header sends the whole representation.
            return null;
        }

        [, $from, $to] = $m;

        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            // `bytes=-500` is the last 500 bytes, not the first.
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

    /**
     * Inline for anything a browser can play, an attachment for the rest.
     *
     * `?download=1` forces the attachment, because "save this" is a thing
     * somebody wants to do with a video too.
     */
    private function disposition(ServerRequestInterface $request, string $filename): string
    {
        $forced = filter_var($request->getQueryParams()['download'] ?? false, FILTER_VALIDATE_BOOL);

        // The filename is echoed into a header, so anything that could end the
        // quoted string or start another header goes.
        $safe = preg_replace('/[^\w .\-()]+/u', '_', $filename) ?? 'file';

        return sprintf('%s; filename="%s"', $forced ? 'attachment' : 'inline', $safe);
    }
}
