<?php

declare(strict_types=1);

namespace Central\Api\Domain\Note;

/**
 * A submitted note, checked.
 *
 * Pure, so the rules can be tested without a database. There are not many of
 * them — a note is a piece of text — but "not many rules" is not "no rules",
 * and `user/notes/create.php` checked only that `content` was non-empty before
 * handing the rest to a statement that could not run.
 */
final readonly class NoteInput
{
    private const MAX_CONTENT = 20_000;
    private const MAX_TITLE   = 200;

    private function __construct(
        public string $content,
        public ?string $title,
        public ?int $positionSeconds,
        public ?bool $isPinned,
        public ?string $lessonId,
        /**
         * Whether the body mentioned the optional fields at all.
         *
         * Without this, "omitted" and "cleared" are the same request, and
         * saving a correction to the text silently drops the title and the
         * position with it — which is exactly what an edit form that only
         * sends the changed field would do to somebody's note.
         */
        public bool $touchesTitle = false,
        public bool $touchesPosition = false,
    ) {
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string> $violations
     * @param  bool                  $requireLesson a new note needs one; an edit does not move
     */
    public static function fromBody(array $body, array &$violations, bool $requireLesson): ?self
    {
        $content = trim((string) ($body['content'] ?? ''));

        if ($content === '') {
            $violations['content'] = 'Write something first.';
        } elseif (mb_strlen($content) > self::MAX_CONTENT) {
            $violations['content'] = sprintf('A note is at most %d characters.', self::MAX_CONTENT);
        }

        $lessonId = self::uuid($body['lessonId'] ?? null);

        if ($requireLesson && $lessonId === null) {
            $violations['lessonId'] = 'Say which lesson this note is about.';
        }

        $position = null;

        if (($body['positionSeconds'] ?? null) !== null && $body['positionSeconds'] !== '') {
            $position = (int) $body['positionSeconds'];

            if ($position < 0) {
                // A negative position is a player reporting nonsense, and
                // storing it makes a note that jumps to nowhere.
                $violations['positionSeconds'] = 'A position in a recording cannot be negative.';
            }
        }

        if ($violations !== []) {
            return null;
        }

        $title = trim((string) ($body['title'] ?? ''));

        return new self(
            content: $content,
            title: $title === '' ? null : mb_substr($title, 0, self::MAX_TITLE),
            positionSeconds: $position,
            isPinned: array_key_exists('isPinned', $body)
                ? filter_var($body['isPinned'], FILTER_VALIDATE_BOOL)
                : null,
            lessonId: $lessonId,
            touchesTitle: array_key_exists('title', $body),
            touchesPosition: array_key_exists('positionSeconds', $body),
        );
    }

    private static function uuid(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^[0-9a-f-]{36}$/i', $value) === 1 ? $value : null;
    }
}
