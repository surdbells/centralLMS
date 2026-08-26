<?php

declare(strict_types=1);

namespace Central\Api\Application\Http;

use Psr\Http\Message\StreamInterface;

/**
 * A window onto part of another stream.
 *
 * Needed because the emitter rewinds a seekable body before writing it, which
 * is right for an ordinary response and wrong for a partial one: seeking to
 * byte 1,000,000 and handing the response the same stream produces a `206`
 * whose body starts at zero, and a video player that seeks to the end plays
 * the beginning.
 *
 * Rewinding this seeks to the start of the range instead, and reading past the
 * end of it returns nothing.
 */
final class ByteRangeStream implements StreamInterface
{
    private int $position;

    public function __construct(
        private readonly StreamInterface $inner,
        private readonly int $start,
        private readonly int $length,
    ) {
        $this->position = 0;
        $this->inner->seek($this->start);
    }

    /**
     * The length of the range, never the length of the file.
     *
     * The emitter reads this many bytes when no `Content-Length` is set, so
     * answering with the file's size sends the whole thing under a `206`.
     */
    public function getSize(): int
    {
        return $this->length;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->length + $offset,
            default  => $offset,
        };

        $this->position = max(0, min($this->length, $target));
        $this->inner->seek($this->start + $this->position);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= $this->length || $this->inner->eof();
    }

    public function read(int $length): string
    {
        $remaining = $this->length - $this->position;

        if ($remaining <= 0 || $length <= 0) {
            return '';
        }

        $chunk = $this->inner->read(min($length, $remaining));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $contents = '';

        while (!$this->eof()) {
            $chunk = $this->read(65_536);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    /** A response body, never a destination. */
    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('A byte range is read-only.');
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $this->inner->getMetadata($key);
    }
}
