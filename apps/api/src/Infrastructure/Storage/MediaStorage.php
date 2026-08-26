<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Where uploaded bytes go, and how they come back.
 *
 * A thin seam over Flysystem so the rest of the application never holds a
 * storage path. Local disk in development, S3 in production, the same code
 * either way — see the `FilesystemOperator` binding in `config/container.php`.
 */
final readonly class MediaStorage
{
    public function __construct(
        private FilesystemOperator $files,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Writes an upload and reports what was actually stored.
     *
     * The size is measured here rather than taken from the request: the
     * `Content-Length` of a multipart part is supplied by the client, and an
     * upload that claims to be 1 KB and delivers 4 GB is how a storage quota
     * checked against the claim gets past it.
     *
     * @return array{size: int, sha256: string}
     */
    public function store(UploadedFileInterface $upload, string $key): array
    {
        $stream = $upload->getStream()->detach();

        if (!is_resource($stream)) {
            throw new \RuntimeException('The upload could not be read.');
        }

        // One pass for the checksum, then rewind for the write. Hashing the
        // stream as it is written would be one pass instead of two, but
        // Flysystem's adapters are free to consume it however they like.
        rewind($stream);

        $context = hash_init('sha256');
        $size    = 0;

        while (!feof($stream)) {
            $chunk = fread($stream, 1_048_576);

            if ($chunk === false) {
                break;
            }

            $size += strlen($chunk);
            hash_update($context, $chunk);
        }

        $sha256 = hash_final($context);

        rewind($stream);

        try {
            $this->files->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return ['size' => $size, 'sha256' => $sha256];
    }

    /**
     * @return resource
     */
    public function read(string $key)
    {
        return $this->files->readStream($key);
    }

    public function has(string $key): bool
    {
        try {
            return $this->files->fileExists($key);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Removes stored bytes, and never fails the request for it.
     *
     * A file the database has already forgotten is a wasted byte, not a broken
     * delete — and telling somebody their file could not be removed when the
     * row is gone leaves them with no way to try again.
     */
    public function remove(string ...$keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->files->delete($key);
            } catch (FilesystemException $e) {
                $this->logger->warning('Orphaned stored file: {key}', [
                    'key'   => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
