<?php

declare(strict_types=1);

namespace Central\Api\Domain\Messaging;

/**
 * The identity of a two-person conversation, whichever way round it is asked.
 *
 * Pure, and small, and the reason a pair cannot end up with two conversations.
 * The legacy had four direct conversations and nothing stopping a fifth between
 * a pair that already had one — which splits a conversation in half and hides
 * the older one behind the newer.
 *
 * Sorting the two ids is the whole trick: Ada writing to Grace and Grace
 * writing to Ada produce the same key, so the unique index catches the second
 * attempt no matter who starts it.
 */
final readonly class DirectKey
{
    private function __construct(public string $value)
    {
    }

    public static function between(string $one, string $other): self
    {
        $ids = [trim($one), trim($other)];
        sort($ids);

        return new self(implode(':', $ids));
    }

    /**
     * Whether these two are the same person.
     *
     * A conversation with oneself is a notepad, and there is a notes module
     * for that.
     */
    public static function isSelf(string $one, string $other): bool
    {
        return trim($one) === trim($other);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
