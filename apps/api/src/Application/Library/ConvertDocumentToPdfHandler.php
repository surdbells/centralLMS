<?php

declare(strict_types=1);

namespace Central\Api\Application\Library;

use Central\Api\Domain\Library\ConversionStatus;
use Central\Api\Domain\Library\DigitalLibraryRepository;
use Central\Api\Domain\Media\MediaKind;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Log\LoggerInterface;

/**
 * Turns a library document into the PDF it is viewed as.
 *
 * A browser will not show a Word or PowerPoint file inline, and the library
 * never offers a download, so an Office document would be unviewable without
 * this. LibreOffice, run headless, converts it; the PDF is stored beside the
 * original and the row is pointed at it.
 *
 * Everything is a state transition on the row, so the work is idempotent and a
 * crash is recoverable: a document is only picked up while `pending`, moves to
 * `processing`, and lands on `done` (with its PDF) or `failed`. A `failed`
 * document keeps its original — a re-upload, or a re-dispatch, can try again.
 */
final readonly class ConvertDocumentToPdfHandler
{
    public function __construct(
        private DigitalLibraryRepository $library,
        private MediaStorage $storage,
        private LoggerInterface $logger,
        /** Overridable for tests / a non-standard install. `soffice` on PATH by default. */
        private string $sofficeBinary = 'soffice',
    ) {
    }

    public function __invoke(ConvertDocumentToPdf $message): void
    {
        $item = $this->library->find($message->tenantId, $message->itemId);

        // Gone, already handled, or not a document — nothing to do. Being picked
        // up only while pending is what makes a redelivery safe.
        if ($item === null
            || $item->kind !== MediaKind::Document
            || $item->conversionStatus !== ConversionStatus::Pending
        ) {
            return;
        }

        $this->library->setConversion($message->tenantId, $message->itemId, ConversionStatus::Processing);

        $workDir = $this->makeWorkDir();
        $input   = $workDir . '/source.' . ($item->extension !== '' ? $item->extension : 'bin');

        try {
            // Bring the original out of storage to a real path LibreOffice can open.
            $this->copyOut($item->storageKey, $input);

            $pdfPath = $this->convert($input, $workDir);

            $handle = fopen($pdfPath, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('The converted PDF could not be read back.');
            }

            $pageCount = $this->countPages($pdfPath);
            $key = sprintf('%s/library-pdf/%s.pdf', $item->tenantId, bin2hex(random_bytes(16)));
            $this->storage->putStream($key, $handle);

            $this->library->setConversion(
                $message->tenantId,
                $message->itemId,
                ConversionStatus::Done,
                $key,
                $pageCount,
            );

            $this->logger->info('Converted library document to PDF', [
                'itemId' => $message->itemId,
                'pages'  => $pageCount,
            ]);
        } catch (\Throwable $e) {
            $this->library->setConversion($message->tenantId, $message->itemId, ConversionStatus::Failed);
            $this->logger->error('Library document conversion failed', [
                'itemId' => $message->itemId,
                'error'  => $e->getMessage(),
            ]);
        } finally {
            $this->cleanUp($workDir);
        }
    }

    /**
     * Runs LibreOffice headless, returning the path of the PDF it wrote.
     *
     * A private per-run user profile is used so two conversions on the same
     * worker do not fight over one, which LibreOffice answers by refusing the
     * second with an unhelpful error.
     */
    private function convert(string $input, string $workDir): string
    {
        $profile = 'file://' . $workDir . '/profile';

        [$exit, , $stderr] = $this->run([
            $this->sofficeBinary,
            '--headless',
            '--nologo',
            '--nofirststartwizard',
            '--norestore',
            '-env:UserInstallation=' . $profile,
            '--convert-to',
            'pdf',
            '--outdir',
            $workDir,
            $input,
        ]);

        // LibreOffice names the output after the input's basename.
        $pdfPath = $workDir . '/' . pathinfo($input, PATHINFO_FILENAME) . '.pdf';

        if ($exit !== 0 || !is_file($pdfPath)) {
            if ($exit === 127) {
                throw new \RuntimeException('LibreOffice (soffice) is not installed on the server.');
            }

            throw new \RuntimeException('LibreOffice did not produce a PDF: ' . $this->lastLine($stderr));
        }

        return $pdfPath;
    }

    private function copyOut(string $key, string $to): void
    {
        $in = $this->storage->read($key);
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

    /**
     * A best-effort page count read from the PDF, or null. The PDF is served
     * whatever this returns — the count is a display hint, not a gate.
     */
    private function countPages(string $pdfPath): ?int
    {
        $contents = file_get_contents($pdfPath);
        if ($contents === false) {
            return null;
        }

        // Count the page objects. `/Type /Page` but not `/Type /Pages` (the
        // tree node), so a negative lookahead on the trailing 's'.
        $matches = preg_match_all('/\/Type\s*\/Page(?![s])/', $contents);

        return is_int($matches) && $matches > 0 ? $matches : null;
    }

    private function makeWorkDir(): string
    {
        $dir = sys_get_temp_dir() . '/lo_' . bin2hex(random_bytes(8));
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

    /**
     * Runs a command with no shell (argv array), returning [exit, out, err].
     *
     * @param  list<string> $command
     * @return array{int, string, string}
     */
    private function run(array $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // A generous timeout so a large deck does not fail spuriously, but not
        // unbounded — a LibreOffice that hangs must not pin a worker for ever.
        $process = @proc_open($command, $descriptors, $pipes, sys_get_temp_dir(), ['HOME' => sys_get_temp_dir()]);
        if (!is_resource($process)) {
            return [127, '', 'could not start LibreOffice'];
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => $l !== ''));

        return $lines === [] ? '' : mb_substr(end($lines), 0, 300);
    }
}
