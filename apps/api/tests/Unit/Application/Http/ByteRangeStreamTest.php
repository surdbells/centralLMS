<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Http;

use Central\Api\Application\Http\ByteRangeStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Stream;

/**
 * A window onto part of a file.
 *
 * The test that matters is `rewinding_returns_to_the_start_of_the_range`.
 * Everything else here is bookkeeping; that one is the bug this class exists
 * for. The emitter rewinds a seekable body before writing it, so a `206` built
 * by seeking the underlying stream emits from byte zero — the right length of
 * the wrong bytes, which a video player renders as the beginning of the file
 * every time somebody drags the scrubber.
 */
#[CoversClass(ByteRangeStream::class)]
final class ByteRangeStreamTest extends TestCase
{
    private const CONTENT = 'abcdefghijklmnopqrstuvwxyz';

    #[Test]
    public function it_reads_only_its_own_slice(): void
    {
        self::assertSame('fghij', $this->range(5, 5)->getContents());
    }

    #[Test]
    public function rewinding_returns_to_the_start_of_the_range(): void
    {
        $range = $this->range(5, 5);

        self::assertSame('fg', $range->read(2));

        $range->rewind();

        self::assertSame('fghij', $range->getContents());
    }

    #[Test]
    public function it_reports_the_length_of_the_range_not_the_file(): void
    {
        // The emitter reads getSize() bytes when no Content-Length is set, so
        // reporting the whole file here sends 26 bytes for a 5-byte range.
        self::assertSame(5, $this->range(5, 5)->getSize());
    }

    #[Test]
    public function reading_past_the_end_of_the_range_gives_nothing(): void
    {
        $range = $this->range(0, 3);

        self::assertSame('abc', $range->read(100));
        self::assertSame('', $range->read(100));
        self::assertTrue($range->eof());
    }

    #[Test]
    public function the_tail_of_a_file_is_a_range_like_any_other(): void
    {
        self::assertSame('xyz', $this->range(23, 3)->getContents());
    }

    #[Test]
    public function seeking_is_relative_to_the_range(): void
    {
        $range = $this->range(10, 6);

        $range->seek(2);
        self::assertSame(2, $range->tell());
        self::assertSame('mnop', $range->getContents());

        $range->seek(-2, SEEK_END);
        self::assertSame('op', $range->getContents());
    }

    #[Test]
    public function seeking_past_either_edge_is_clamped(): void
    {
        $range = $this->range(5, 5);

        $range->seek(500);
        self::assertSame('', $range->read(10));

        $range->seek(-500);
        self::assertSame('fghij', $range->getContents());
    }

    #[Test]
    public function it_refuses_to_be_written_to(): void
    {
        $range = $this->range(0, 5);

        self::assertFalse($range->isWritable());

        $this->expectException(\RuntimeException::class);

        $range->write('nope');
    }

    private function range(int $start, int $length): ByteRangeStream
    {
        $handle = fopen('php://temp', 'r+');
        self::assertIsResource($handle);
        fwrite($handle, self::CONTENT);
        rewind($handle);

        return new ByteRangeStream(new Stream($handle), $start, $length);
    }
}
