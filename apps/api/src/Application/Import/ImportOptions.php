<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

use Central\Api\Application\Action\Auth\PasswordResetAction;
use Central\Api\Domain\Identity\PasswordTokenPurpose;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The people-creating options both workbook importers take, read the same way,
 * and the invitation send that follows a commit.
 *
 * Multipart fields arrive as strings, so `createMissing` and `sendInvites` are
 * "true"/"false" (also 1/0, yes/no, on/off); both default to false here — the
 * web wizard is what defaults the checkbox on.
 */
final class ImportOptions
{
    /**
     * @param array<mixed> $body
     *
     * @return array{createMissing: bool, sendInvites: bool, roleId: ?string}
     */
    public static function read(array $body): array
    {
        $roleId = $body['roleId'] ?? null;
        $roleId = is_string($roleId) && trim($roleId) !== '' ? trim($roleId) : null;

        return [
            'createMissing' => filter_var($body['createMissing'] ?? false, FILTER_VALIDATE_BOOL),
            'sendInvites'   => filter_var($body['sendInvites'] ?? false, FILTER_VALIDATE_BOOL),
            'roleId'        => $roleId,
        ];
    }

    /**
     * Sends the standard invitation (the same token link the user form sends)
     * to each newly created person. Run after the import has committed, so no
     * email goes out for an account a rollback took back.
     *
     * @param array<string> $userIds
     *
     * @return array{sent: int, failed: list<string>}
     */
    public static function invite(
        PasswordResetAction $passwords,
        ServerRequestInterface $request,
        string $tenantId,
        array $userIds,
    ): array {
        $sent   = 0;
        $failed = [];

        foreach (array_values(array_unique($userIds)) as $userId) {
            try {
                if ($passwords->send($request, $userId, $tenantId, PasswordTokenPurpose::Invitation)) {
                    ++$sent;
                } else {
                    $failed[] = $userId;
                }
            } catch (\Throwable) {
                // One undeliverable address must not lose the rest.
                $failed[] = $userId;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
