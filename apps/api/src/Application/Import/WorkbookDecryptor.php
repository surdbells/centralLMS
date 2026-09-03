<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

use Psr\Log\LoggerInterface;

/**
 * Opens password-protected Excel workbooks.
 *
 * Several completed JCP workbooks are saved with a *password to open*. Such a
 * file is not a zip — it is an OLE2 compound document holding an encrypted
 * package — so the openspout reader, which opens a workbook as a zip, cannot
 * touch it. openspout has no decryption of its own and neither does PHP; the
 * ECMA-376 agile encryption these files use is decrypted here by shelling out
 * to `msoffcrypto-tool`, which reads the encrypted package and writes back the
 * plain workbook the reader can then open.
 *
 * The password reaches the tool as a process argument, never through a shell
 * (the command is an argv array, so nothing in it is interpreted), and is never
 * logged. A legacy BIFF `.xls` renamed to `.xlsx` wears the same OLE signature
 * as an encrypted file; {@see isEncrypted} tells the two apart so the importer
 * can report each for what it is.
 */
final class WorkbookDecryptor
{
    /** OLE2 compound-document signature — encrypted OOXML and legacy .xls both carry it. */
    private const OLE_MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** A zip local-file header — a plain (or freshly decrypted) .xlsx begins with this. */
    private const ZIP_MAGIC = "PK\x03\x04";

    public function __construct(
        private readonly string $binary = 'msoffcrypto-tool',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether the file is an OLE2 compound document — an encrypted workbook or a
     * legacy BIFF `.xls`, as opposed to a plain (zip) `.xlsx`.
     */
    public function isOleContainer(string $path): bool
    {
        return $this->magic($path, 8) === self::OLE_MAGIC;
    }

    /**
     * Whether an OLE container is specifically an *encrypted* OOXML workbook
     * rather than a legacy `.xls`. The tool answers this without a password.
     * If the tool is missing the question cannot be answered, so this returns
     * false and the file falls through to being treated as a legacy `.xls`.
     */
    public function isEncrypted(string $path): bool
    {
        if (!$this->isOleContainer($path)) {
            return false;
        }

        // `--test` exits 0 for an encrypted OOXML file, non-zero for anything
        // else (a legacy .xls, or the tool being absent).
        [$exit] = $this->run([$this->binary, '--test', $path]);

        return $exit === 0;
    }

    /**
     * Decrypts an encrypted workbook to a fresh temporary file and returns its
     * path. The caller owns that file and must unlink it.
     *
     * @throws DecryptionFailed the tool is absent, the password is wrong, or the
     *         result is not a workbook.
     */
    public function decrypt(string $path, #[\SensitiveParameter] string $password): string
    {
        $out = $this->tempFile();

        [$exit, , $stderr] = $this->run([$this->binary, '-p', $password, $path, $out]);

        // proc_open reports 127 when the binary cannot be found; distinguish
        // that from a wrong password so the message the caller shows is true.
        if ($exit === 127) {
            @unlink($out);
            $this->logger?->error('msoffcrypto-tool is not installed', ['binary' => $this->binary]);

            throw DecryptionFailed::toolMissing();
        }

        // Two independent checks. The tool writes a full-size but still-encrypted
        // file when the password is wrong, so a zero exit is not enough — the
        // result must actually begin like a zip.
        $decrypted = $exit === 0 && $this->magic($out, 4) === self::ZIP_MAGIC;

        if (!$decrypted) {
            @unlink($out);
            // The stderr can name the file but never the password.
            $this->logger?->warning('Workbook decryption failed', [
                'exit'   => $exit,
                'stderr' => $this->lastLine($stderr),
            ]);

            throw DecryptionFailed::wrongPassword();
        }

        return $out;
    }

    // --- internals ------------------------------------------------------------

    /**
     * Runs a command with no shell (an argv array), returning [exit, out, err].
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

        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [127, '', 'could not start the decryption tool'];
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function magic(string $path, int $bytes): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $magic = (string) fread($handle, $bytes);
        fclose($handle);

        return $magic;
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'jcpdec_');

        return $path === false
            ? sys_get_temp_dir() . '/jcpdec_' . bin2hex(random_bytes(8))
            : $path;
    }

    /** The last non-empty line — a Python traceback's message, never the password. */
    private function lastLine(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => $l !== ''));

        return $lines === [] ? '' : mb_substr(end($lines), 0, 300);
    }
}
