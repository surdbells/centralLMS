<?php

declare(strict_types=1);

namespace Central\Api\Domain\People;

use Central\Api\Domain\Identity\UserStatus;

/**
 * Turns a request body into a {@see Person}, or into a list of reasons why not.
 *
 * Pure. The legacy's `User::create()` read 20 properties set by the caller one
 * at a time and validated none of them — an empty email, a role id from
 * another tenant and a hire date of `"soon"` all reached the insert.
 */
final readonly class PersonInput
{
    /** As permissive as the login form's: local@domain.tld, no spaces. */
    private const EMAIL = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string> $violations
     */
    public static function toPerson(
        string $tenantId,
        ?string $id,
        array $input,
        array &$violations,
        ?Person $existing = null,
    ): ?Person {
        $email = strtolower(trim((string) ($input['email'] ?? $existing->email ?? '')));

        if ($email === '') {
            $violations['email'] = 'An email address is required.';
        } elseif (preg_match(self::EMAIL, $email) !== 1) {
            $violations['email'] = 'That is not an email address.';
        } elseif (mb_strlen($email) > 255) {
            $violations['email'] = 'That address is too long.';
        }

        $firstName = trim((string) ($input['firstName'] ?? $existing->firstName ?? ''));
        $lastName  = trim((string) ($input['lastName'] ?? $existing->lastName ?? ''));

        if ($firstName === '') {
            $violations['firstName'] = 'A first name is required.';
        }

        if ($lastName === '') {
            $violations['lastName'] = 'A last name is required.';
        }

        $status = $existing->status ?? UserStatus::Invited;

        if (isset($input['status'])) {
            $status = UserStatus::tryFrom(strtolower(trim((string) $input['status'])))
                ?? $status;

            if (UserStatus::tryFrom(strtolower(trim((string) $input['status']))) === null) {
                $violations['status'] = 'Choose one of: ' . implode(', ', array_map(
                    static fn (UserStatus $s): string => $s->value,
                    UserStatus::cases(),
                )) . '.';
            }
        }

        $hireDate      = self::date($input, 'hireDate', $existing?->hireDate, $violations);
        $probationEnds = self::date($input, 'probationEndDate', $existing?->probationEndDate, $violations);

        // A probation that ends before the person starts is a typo, and it
        // drives a report somebody acts on.
        if ($hireDate !== null && $probationEnds !== null && $probationEnds < $hireDate) {
            $violations['probationEndDate'] = 'Probation cannot end before the start date.';
        }

        $managerId = self::uuid($input, 'managerId', $existing?->managerId);

        if ($managerId !== null && $id !== null && $managerId === $id) {
            $violations['managerId'] = 'Somebody cannot report to themselves.';
        }

        $roleIds = self::roleIds($input, $existing->roleIds ?? [], $violations);

        if ($violations !== []) {
            return null;
        }

        return new Person(
            id: $id,
            tenantId: $tenantId,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
            status: $status,
            phone: self::text($input, 'phone', $existing?->phone),
            avatarUrl: self::text($input, 'avatarUrl', $existing?->avatarUrl),
            locale: self::text($input, 'locale', $existing?->locale) ?? 'en',
            managerId: $managerId,
            branchId: self::uuid($input, 'branchId', $existing?->branchId),
            departmentId: self::uuid($input, 'departmentId', $existing?->departmentId),
            teamId: self::uuid($input, 'teamId', $existing?->teamId),
            levelId: self::uuid($input, 'levelId', $existing?->levelId),
            jobTitleId: self::uuid($input, 'jobTitleId', $existing?->jobTitleId),
            hireDate: $hireDate,
            probationEndDate: $probationEnds,
            roleIds: $roleIds,
            twoFactorEnabled: isset($input['twoFactor'])
                ? filter_var($input['twoFactor'], FILTER_VALIDATE_BOOL)
                : ($existing->twoFactorEnabled ?? false),
        );
    }

    /**
     * A password strong enough to be worth having.
     *
     * The legacy accepted anything, including the empty string — `create.php`
     * hashed `$_POST['password']` without looking at it.
     *
     * @param array<string, string> $violations
     */
    public static function checkPassword(string $password, array &$violations, string $field = 'password'): void
    {
        if (mb_strlen($password) < 12) {
            // Length first: it does more for strength than any character class
            // rule, and a 12-character passphrase is easier to remember than
            // eight characters of punctuation.
            $violations[$field] = 'Use at least 12 characters.';

            return;
        }

        if (mb_strlen($password) > 4096) {
            // An unbounded password is a way to make the hashing function burn
            // CPU on request.
            $violations[$field] = 'That is too long.';

            return;
        }

        if (preg_match('/[a-zA-Z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            $violations[$field] = 'Include at least one letter and one number.';
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>          $existing
     * @param  array<string, string> $violations
     * @return list<string>
     */
    private static function roleIds(array $input, array $existing, array &$violations): array
    {
        if (!array_key_exists('roleIds', $input)) {
            return $existing;
        }

        if (!is_array($input['roleIds'])) {
            $violations['roleIds'] = 'Send a list of role ids.';

            return $existing;
        }

        $ids = [];

        foreach ($input['roleIds'] as $roleId) {
            $roleId = trim((string) $roleId);

            if ($roleId !== '' && !in_array($roleId, $ids, true)) {
                $ids[] = $roleId;
            }
        }

        if ($ids === []) {
            // An account with no role can sign in and see nothing, which
            // reads as the application being broken.
            $violations['roleIds'] = 'Give them at least one role.';
        }

        return $ids;
    }

    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $violations
     */
    private static function date(
        array $input,
        string $key,
        ?\DateTimeImmutable $existing,
        array &$violations,
    ): ?\DateTimeImmutable {
        if (!array_key_exists($key, $input)) {
            return $existing;
        }

        $raw = trim((string) ($input[$key] ?? ''));

        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            $violations[$key] = 'Use a date in the form 2026-03-01.';

            return null;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function text(array $input, string $key, ?string $existing): ?string
    {
        if (!array_key_exists($key, $input)) {
            return $existing;
        }

        $value = trim((string) ($input[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function uuid(array $input, string $key, ?string $existing): ?string
    {
        if (!array_key_exists($key, $input)) {
            return $existing;
        }

        $value = trim((string) ($input[$key] ?? ''));

        // Not a validity check — a foreign key settles that. This only keeps
        // obvious rubbish out of a bound parameter.
        return preg_match('/^[0-9a-f-]{36}$/i', $value) === 1 ? $value : null;
    }
}
