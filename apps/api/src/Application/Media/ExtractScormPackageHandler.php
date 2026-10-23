<?php

declare(strict_types=1);

namespace Central\Api\Application\Media;

use Central\Api\Domain\Media\MediaKind;
use Central\Api\Domain\Media\MediaRepository;
use Central\Api\Domain\Media\ScormManifest;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Log\LoggerInterface;

/**
 * Unzips a SCORM package and records how it launches.
 *
 * A SCORM package is a zip: a manifest (`imsmanifest.xml`) plus the HTML, JS and
 * media that make up the course. Served as the zip it just downloads; to play
 * it the files have to be unpacked and the entry page served to an iframe. This
 * does the unpacking — into storage, under `{tenant}/scorm/{mediaId}/…`, beside
 * every other kind — and reads the manifest for the entry file and the SCORM
 * version, which {@see \Central\Api\Application\Action\Media\ScormContentAction}
 * then serves.
 *
 * Idempotent and safe to redeliver: a package is only picked up while its
 * `extracted_path` is null, and a failure leaves it null so it can be tried
 * again. Entry names are validated before anything is written, so a package
 * cannot write a file outside its own folder (zip-slip), and the uncompressed
 * size and file count are capped so a zip bomb cannot fill the disk.
 */
final readonly class ExtractScormPackageHandler
{
    /** A package may not unpack to more than this, nor to more files than below. */
    private const MAX_UNCOMPRESSED_BYTES = 3_221_225_472; // 3 GB
    private const MAX_ENTRIES = 50_000;

    public function __construct(
        private MediaRepository $media,
        private MediaStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExtractScormPackage $message): void
    {
        $item = $this->media->find($message->tenantId, $message->mediaId);

        // Gone, not a package, or already unpacked — nothing to do. Being
        // picked up only while unextracted is what makes a redelivery safe.
        if ($item === null || $item->kind !== MediaKind::Scorm || $item->extractedPath !== null) {
            return;
        }

        if (!$this->storage->has($item->storageKey)) {
            $this->logger->warning('SCORM package has no stored file', ['mediaId' => $message->mediaId]);

            return;
        }

        // So the upload screen can show "unpacking…" rather than guess.
        $this->media->setScormStatus($message->tenantId, $message->mediaId, 'processing');

        $workDir = $this->makeWorkDir();
        $zipPath = $workDir . '/package.zip';
        $outDir  = $workDir . '/out';

        try {
            $this->copyOut($item->storageKey, $zipPath);

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('The SCORM package is not a readable zip.');
            }

            $this->guard($zip);

            if (!mkdir($outDir, 0700, true) && !is_dir($outDir)) {
                throw new \RuntimeException('A working directory could not be created.');
            }

            if (!$zip->extractTo($outDir)) {
                $zip->close();

                throw new \RuntimeException('The SCORM package could not be unpacked.');
            }

            $zip->close();

            // The manifest fixes the content root: standard packages keep it at
            // the zip root, but some nest everything one folder down.
            $manifest = $this->findManifest($outDir);

            if ($manifest === null) {
                throw new \RuntimeException('The package has no imsmanifest.xml — it is not a SCORM package.');
            }

            $root = \dirname($manifest);
            $xml  = file_get_contents($manifest);

            if ($xml === false) {
                throw new \RuntimeException('The manifest could not be read.');
            }

            $parsed = ScormManifest::read($xml);

            $prefix = sprintf('%s/scorm/%s', $item->tenantId, $message->mediaId);
            $this->upload($root, $prefix);

            $this->media->markExtracted(
                $item->tenantId,
                $message->mediaId,
                $prefix,
                $parsed->launchUrl,
                $parsed->version,
            );

            $this->logger->info('Unpacked SCORM package', [
                'mediaId' => $message->mediaId,
                'launch'  => $parsed->launchUrl,
                'scorm'   => $parsed->version,
            ]);
        } catch (\Throwable $e) {
            // Left unextracted so it can be retried; any partial upload is
            // overwritten by the next run writing the same keys. The status
            // lets the upload screen say so instead of waiting for ever.
            $this->media->setScormStatus($message->tenantId, $message->mediaId, 'failed');

            $this->logger->error('SCORM extraction failed', [
                'mediaId' => $message->mediaId,
                'error'   => $e->getMessage(),
            ]);
        } finally {
            $this->cleanUp($workDir);
        }
    }

    /**
     * Refuses a package before a byte of it is written.
     *
     * Every entry name must stay inside the output folder (no `..`, no absolute
     * path, no drive-letter or backslash trickery), and the whole thing must fit
     * the caps — a zip that claims to be a megabyte and unpacks to a terabyte is
     * how a worker fills a disk.
     */
    private function guard(\ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new \RuntimeException('The SCORM package has too many files.');
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                throw new \RuntimeException('The SCORM package has an unreadable entry.');
            }

            $name = (string) $stat['name'];

            if (
                str_contains($name, '..')
                || str_starts_with($name, '/')
                || str_starts_with($name, '\\')
                || preg_match('#^[A-Za-z]:#', $name) === 1
            ) {
                throw new \RuntimeException(sprintf('The SCORM package has an unsafe path: %s', $name));
            }

            $total += (int) $stat['size'];

            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new \RuntimeException('The SCORM package is too large once unpacked.');
            }
        }
    }

    /**
     * The path of the first `imsmanifest.xml` under $dir, or null.
     */
    private function findManifest(string $dir): ?string
    {
        $root = $dir . '/imsmanifest.xml';

        if (is_file($root)) {
            return $root;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isFile() && strtolower($item->getFilename()) === 'imsmanifest.xml') {
                return $item->getPathname();
            }
        }

        return null;
    }

    /**
     * Streams every file under $dir into storage, under $prefix.
     */
    private function upload(string $dir, string $prefix): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        $base = rtrim($dir, '/\\');

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($base) + 1));
            $handle   = fopen($item->getPathname(), 'rb');

            if ($handle === false) {
                throw new \RuntimeException(sprintf('A package file could not be read: %s', $relative));
            }

            $this->storage->putStream($prefix . '/' . $relative, $handle);
        }
    }

    private function copyOut(string $key, string $to): void
    {
        $in  = $this->storage->read($key);
        $out = fopen($to, 'wb');

        if ($out === false) {
            throw new \RuntimeException('A working file could not be written.');
        }

        try {
            stream_copy_to_stream($in, $out);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            fclose($out);
        }
    }

    private function makeWorkDir(): string
    {
        $dir = sys_get_temp_dir() . '/scorm_' . bin2hex(random_bytes(8));

        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('A working directory could not be created.');
        }

        return $dir;
    }

    private function cleanUp(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
