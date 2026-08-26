<?php

declare(strict_types=1);

namespace Central\Api\Domain\Audit;

/**
 * One thing somebody did.
 *
 * The action is the route's name — `users.create`, `roles.delete` — rather than
 * a string each action passes in. Deriving it means a new endpoint is audited
 * the moment it is routed, and an endpoint's audit entry cannot describe
 * something other than what it does.
 */
final readonly class AuditEvent
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public ?string $actorId,
        /**
         * Kept on the row, because it survives the person: an audit entry
         * outlives its actor and the foreign key goes null when they leave.
         */
        public string $actorEmail,
        public string $action,
        public string $method,
        public string $path,
        public ?string $subjectId,
        public int $status,
        /**
         * Resolved by joining `users` at read time, so the trail shows what
         * somebody is called now. Empty once they are gone, and the address
         * stands in.
         */
        public string $actorName = '',
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * The action as a sentence, from its own name.
     *
     * `users.create` is "Created a user". Derived rather than looked up in a
     * table of labels, which would be one more thing to forget when a route is
     * added — an unrecognised verb falls back to the name itself, which is at
     * least honest.
     */
    public function describe(): string
    {
        $parts  = explode('.', $this->action);
        $verb   = array_pop($parts) ?? '';
        $module = implode(' ', $parts);

        // Verbs that already name what they act on. "Imported a user" is
        // wrong twice over — an import is people, plural, and the module it
        // hangs off adds nothing to the sentence.
        $whole = match ($verb) {
            'import'  => 'Imported people from a file',
            'preview' => 'Checked a file of people',
            default   => null,
        };

        if ($whole !== null) {
            return $whole;
        }

        $said = match ($verb) {
            'create'      => 'Created',
            'update'      => 'Updated',
            'delete'      => 'Deleted',
            'status'      => 'Changed the status of',
            'password'    => 'Set a password on',
            'reorder',
            'order'       => 'Reordered',
            'set'         => 'Set',
            'save'        => 'Saved',
            'remove'      => 'Removed',
            'enroll'      => 'Enrolled somebody on',
            'unenroll'    => 'Removed somebody from',
            'upload'      => 'Uploaded to',
            'revoke'      => 'Revoked',
            'issue'       => 'Issued',
            'publish'     => 'Published',
            default       => null,
        };

        if ($said === null) {
            return ucfirst(str_replace('.', ' ', $this->action));
        }

        // `me.*` is somebody acting on their own record. Saying "a profile"
        // would read as though they had edited somebody's.
        if (str_starts_with($this->action, 'me.')) {
            $own = trim(substr($module, strlen('me')));

            return $own === ''
                ? $said . ' their own record'
                : sprintf('%s their own %s', $said, $this->singular($own));
        }

        return sprintf('%s %s %s', $said, $this->article($module), $this->singular($module));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'actorId'     => $this->actorId,
            // The address is the fallback, not a second field to render: a
            // row for a departed colleague should still name somebody.
            'actorName'   => $this->actorName !== '' ? $this->actorName : ($this->actorEmail ?: null),
            'actorEmail'  => $this->actorEmail === '' ? null : $this->actorEmail,
            'action'      => $this->action,
            'description' => $this->describe(),
            'method'      => $this->method,
            'path'        => $this->path,
            'subjectId'   => $this->subjectId,
            'status'      => $this->status,
            'ipAddress'   => $this->ipAddress,
            'userAgent'   => $this->userAgent,
            'requestId'   => $this->requestId,
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function singular(string $module): string
    {
        if ($module === '') {
            return 'something';
        }

        // Route groups are plural — `users`, `courses`, `lessons`. `settings`
        // and `progress` are not, and dropping their last letter would produce
        // words that are not words.
        if (in_array($module, ['settings', 'progress', 'gamification', 'audit'], true)) {
            return $module;
        }

        return str_ends_with($module, 's') ? substr($module, 0, -1) : $module;
    }

    /**
     * "an enrollment", but "a user".
     *
     * `u` is left out: the letter is a vowel and the sound usually is not, so
     * including it produces "an user" and "an unit". The rule is about how a
     * word is said, and this is as close as spelling gets to it.
     */
    private function article(string $module): string
    {
        return str_contains('aeio', substr($this->singular($module), 0, 1)) ? 'an' : 'a';
    }
}
