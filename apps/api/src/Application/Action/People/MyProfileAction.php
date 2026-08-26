<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\People;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Identity\RefreshTokenRepository;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonRepository;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * What somebody can change about themselves.
 *
 * GET    /v1/me/profile
 * PUT    /v1/me/profile
 * GET    /v1/me/consents
 * PUT    /v1/me/consents
 * POST   /v1/me/avatar
 * DELETE /v1/me/avatar
 * GET    /v1/me/sessions
 * DELETE /v1/me/sessions/{id}
 *
 * No permission is checked anywhere here, and no id is ever read from the
 * request: every route acts on whoever the token says is calling. That is the
 * whole security model of this file. The legacy's `update-profile` took a
 * `user_id` from the request body and checked nothing, so any signed-in
 * account could rewrite anybody's name and phone number, in any tenant.
 *
 * **Not everything on the record is here.** Email, status, roles and where
 * somebody sits in the organisation are an administrator's to set — a person
 * moving themselves into another department would silently change which
 * reports they appear in and who their manager sees.
 *
 * Changing a password is not here either: `POST /v1/users/{id}/password`
 * already handles somebody changing their own and asks for the current one.
 * A second route for the same act is how the two end up disagreeing about
 * whether the current password is required.
 */
final class MyProfileAction extends PeopleAction
{
    /** What a consent may be called. Anything else is refused, not stored. */
    private const CONSENTS = ['privacy', 'marketing', 'dataProcessing'];

    private const AVATAR_MAX_BYTES = 2 * 1024 * 1024;

    /** @var array<string, string> mime type to extension */
    private const AVATAR_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        PersonRepository $people,
        private readonly RefreshTokenRepository $tokens,
        private readonly MediaStorage $storage,
        private readonly Clock $clock,
    ) {
        parent::__construct($people);
    }

    // --- the record ---------------------------------------------------------

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, $this->me($request)->toArray());
    }

    /**
     * Name, phone, language, and nothing else.
     *
     * A field that is absent is left alone; a field that is present and empty
     * is cleared. Sending the whole record back would let a client that read
     * the profile a minute ago undo somebody else's change to it.
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $me   = $this->me($request);
        $body = $this->body($request);

        $violations = [];

        $firstName = $this->name($body, 'firstName', $me->firstName, $violations);
        $lastName  = $this->name($body, 'lastName', $me->lastName, $violations);
        $phone     = array_key_exists('phone', $body) ? $this->textOrNull($body['phone']) : $me->phone;
        $locale    = array_key_exists('locale', $body)
            ? strtolower(trim((string) $body['locale']))
            : $me->locale;

        if ($locale === '' || preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $locale) !== 1) {
            $violations['locale'] = 'Choose a language.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $updated = $this->people->update($this->with($me, [
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'phone'     => $phone,
            'locale'    => $locale,
        ]));

        return Json::ok($response, $updated->toArray());
    }

    // --- consents -----------------------------------------------------------

    public function consents(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, $this->currentConsents($request));
    }

    /**
     * Records what somebody has agreed to.
     *
     * Whole set: an absent name means "not given", because consent that is
     * merely never mentioned again is not consent. The legacy wrote three
     * integer columns and read none of them.
     */
    public function saveConsents(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $unknown  = array_diff(array_keys($body), self::CONSENTS);
        $consents = [];

        if ($unknown !== []) {
            throw ValidationFailed::fromArray([
                'consents' => sprintf('There is no such consent: %s.', implode(', ', $unknown)),
            ]);
        }

        foreach (self::CONSENTS as $name) {
            $consents[$name] = filter_var($body[$name] ?? false, FILTER_VALIDATE_BOOL);
        }

        $this->people->saveConsents($this->tenantId($request), $this->userId($request), $consents);

        return Json::ok($response, $consents);
    }

    // --- avatar -------------------------------------------------------------

    /**
     * Replaces this person's picture.
     *
     * Stored outside the media library on purpose. An avatar is not a library
     * asset: putting one there would give every upload a title, a version
     * chain and a row on the media screen, and would need `media.manage` —
     * which a learner does not have and should not need to change their own
     * picture.
     */
    public function uploadAvatar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $me     = $this->me($request);
        $upload = $this->file($request);

        $type = strtolower((string) $upload->getClientMediaType());

        if (!array_key_exists($type, self::AVATAR_TYPES)) {
            throw ValidationFailed::fromArray([
                'file' => 'An avatar must be a JPEG, PNG, GIF or WebP image.',
            ]);
        }

        $key = $this->avatarKey($me->tenantId, (string) $me->id, self::AVATAR_TYPES[$type]);

        // Everything else this person had goes first, so exactly one avatar
        // file exists per person and the route that serves them can find it by
        // trying the four extensions.
        $this->forgetAvatars($me->tenantId, (string) $me->id, except: $key);

        $stored = $this->storage->store($upload, $key);

        // Checked after writing, because the size a multipart part claims is
        // the client's word for it. The bytes are removed again rather than
        // left orphaned in storage.
        if ($stored['size'] > self::AVATAR_MAX_BYTES) {
            $this->storage->remove($key);

            throw ValidationFailed::fromArray(['file' => 'Keep an avatar under 2 MB.']);
        }

        // The checksum makes the URL change when the picture does, so a
        // browser that cached the old one stops showing it.
        $url = sprintf('/v1/users/%s/avatar?v=%s', $me->id, substr($stored['sha256'], 0, 12));

        return Json::ok($response, $this->people->update($this->with($me, ['avatarUrl' => $url]))->toArray());
    }

    public function removeAvatar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $me = $this->me($request);

        $this->forgetAvatars($me->tenantId, (string) $me->id);

        return Json::ok($response, $this->people->update($this->with($me, ['avatarUrl' => null]))->toArray());
    }

    // --- where they are signed in -------------------------------------------

    /**
     * Every session this account has, newest first.
     *
     * The rows have carried the user agent and the address since the identity
     * slice and nothing read them, so "am I signed in anywhere I did not
     * expect" had no answer.
     */
    public function sessions(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok(
            $response,
            $this->tokens->sessionsFor($this->userId($request), $this->clock->now()),
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeSession(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $revoked = $this->tokens->revokeSession(
            $this->userId($request),
            $args['id'] ?? '',
            $this->clock->now(),
        );

        // Scoped by user in the query, so somebody else's session id is a 404
        // here rather than a silent no-op that looks like success.
        if (!$revoked) {
            throw new EntityNotFound('Session', $args['id'] ?? '');
        }

        return Json::noContent($response);
    }

    // --- helpers ------------------------------------------------------------

    private function me(ServerRequestInterface $request): Person
    {
        return $this->require($request, $this->userId($request));
    }

    /**
     * Where one person's avatar lives.
     *
     * Public so the route that serves the bytes uses the same rule rather than
     * its own copy of the path format.
     */
    public static function avatarKey(string $tenantId, string $userId, string $extension): string
    {
        return sprintf('avatars/%s/%s.%s', $tenantId, $userId, $extension);
    }

    /**
     * The extensions an avatar may have, and the type each is served as.
     *
     * @return array<string, string> extension to mime type
     */
    public static function avatarExtensions(): array
    {
        return array_flip(self::AVATAR_TYPES);
    }

    private function forgetAvatars(string $tenantId, string $userId, string $except = ''): void
    {
        foreach (self::AVATAR_TYPES as $extension) {
            $key = self::avatarKey($tenantId, $userId, $extension);

            if ($key !== $except) {
                $this->storage->remove($key);
            }
        }
    }

    /**
     * @return array<string, bool>
     */
    private function currentConsents(ServerRequestInterface $request): array
    {
        $stored   = $this->people->consentsFor($this->tenantId($request), $this->userId($request));
        $consents = [];

        // Every name always present, so a client never has to tell "not given"
        // apart from "this deployment does not have that consent".
        foreach (self::CONSENTS as $name) {
            $consents[$name] = $stored[$name] ?? false;
        }

        return $consents;
    }

    /**
     * The same person with a few fields replaced.
     *
     * Written out rather than taken from the request body: this is what stops
     * a self-service save from carrying a `status` or a `roleIds` through to
     * the update.
     *
     * @param array{
     *     firstName?: string, lastName?: string, phone?: ?string,
     *     locale?: string, avatarUrl?: ?string
     * } $changes
     */
    private function with(Person $person, array $changes): Person
    {
        return new Person(
            id: $person->id,
            tenantId: $person->tenantId,
            email: $person->email,
            firstName: $changes['firstName'] ?? $person->firstName,
            lastName: $changes['lastName'] ?? $person->lastName,
            status: $person->status,
            phone: array_key_exists('phone', $changes) ? $changes['phone'] : $person->phone,
            avatarUrl: array_key_exists('avatarUrl', $changes) ? $changes['avatarUrl'] : $person->avatarUrl,
            locale: $changes['locale'] ?? $person->locale,
            managerId: $person->managerId,
            branchId: $person->branchId,
            departmentId: $person->departmentId,
            teamId: $person->teamId,
            levelId: $person->levelId,
            jobTitleId: $person->jobTitleId,
            hireDate: $person->hireDate,
            probationEndDate: $person->probationEndDate,
            roleIds: $person->roleIds,
            twoFactorEnabled: $person->twoFactorEnabled,
        );
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $violations
     */
    private function name(array $body, string $field, string $current, array &$violations): string
    {
        if (!array_key_exists($field, $body)) {
            return $current;
        }

        $name = trim((string) $body[$field]);

        if ($name === '') {
            $violations[$field] = 'This cannot be empty.';
        } elseif (mb_strlen($name) > 100) {
            $violations[$field] = 'Keep this to 100 characters or fewer.';
        }

        return $name;
    }

    private function file(ServerRequestInterface $request): UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $file  = $files['file'] ?? $files['avatar'] ?? reset($files);

        if (!$file instanceof UploadedFileInterface) {
            throw ValidationFailed::fromArray(['file' => 'Attach an image.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => 'That file did not arrive in one piece.']);
        }

        return $file;
    }

    private function textOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
