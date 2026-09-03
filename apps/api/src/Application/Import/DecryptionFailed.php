<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

/**
 * A password-protected workbook could not be opened.
 *
 * Carries a reason a caller can show without ever naming the password: either
 * the password was wrong (or absent) or the decryption tool is not installed.
 */
final class DecryptionFailed extends \RuntimeException
{
    public static function wrongPassword(): self
    {
        return new self('The workbook could not be opened with the password provided.');
    }

    public static function toolMissing(): self
    {
        return new self('The workbook is encrypted, but the decryption tool is not available on the server.');
    }
}
