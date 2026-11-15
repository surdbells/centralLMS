<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

/**
 * Splits one Learning Plan cell into the separate trainings (or providers) it
 * lists.
 *
 * Planners put several items in one cell separated by a slash on its own —
 * "Course A /\nCourse B" or "Course A / Course B". A slash that is part of a
 * word ("Technical/Operations") or sits inside parentheses ("(August /
 * December 2025)") is not a separator. So a "/" splits only at parenthesis
 * depth 0 and only when the character before or after it is whitespace.
 *
 * A part that is wholly parenthesised ("X /\n\n(On-the-Job exposure …)") is a
 * note on the previous part, not an item of its own, so it is joined back on.
 */
final class PlanCellSplitter
{
    /**
     * @return list<string>
     */
    public static function split(?string $cell): array
    {
        if ($cell === null) {
            return [];
        }

        $text = self::normaliseSpaces($cell);
        if (trim($text) === '') {
            return [];
        }

        $parts  = [];
        $buffer = '';
        $depth  = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === '/' && self::nextToNewline($text, $i)) {
                // A slash on a line break separates at any depth: a "(" left
                // open earlier in the cell must not swallow the next item.
                $parts[] = $buffer;
                $buffer  = '';
                $depth   = 0;

                continue;
            } elseif ($ch === '/' && $depth === 0) {
                $before = $i > 0 ? $text[$i - 1] : ' ';
                $after  = $i + 1 < $length ? $text[$i + 1] : ' ';

                if (ctype_space($before) || ctype_space($after)) {
                    $parts[] = $buffer;
                    $buffer  = '';

                    continue;
                }
            }

            $buffer .= $ch;
        }
        $parts[] = $buffer;

        $out = [];
        foreach ($parts as $part) {
            $clean = self::clean($part);
            if ($clean === '') {
                continue;
            }

            if ($out !== [] && str_starts_with($clean, '(') && str_ends_with($clean, ')')) {
                $out[count($out) - 1] .= ' ' . $clean;

                continue;
            }

            $out[] = $clean;
        }

        return $out;
    }

    /**
     * Splits a cell into its lines — a line break (optionally after ";") at
     * parenthesis depth 0. Used only as a fallback for cells that list several
     * trainings one per line without a "/"; a wholly parenthesised line is
     * joined back onto the one before.
     *
     * @return list<string>
     */
    public static function splitLines(?string $cell): array
    {
        if ($cell === null) {
            return [];
        }

        $text   = self::normaliseSpaces($cell);
        $parts  = [];
        $buffer = '';
        $depth  = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $ch = $text[$i];

            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === "\n" && $depth === 0) {
                $parts[] = $buffer;
                $buffer  = '';

                continue;
            }

            $buffer .= $ch;
        }
        $parts[] = $buffer;

        $out = [];
        foreach ($parts as $part) {
            $clean = self::clean($part);
            if ($clean === '') {
                continue;
            }

            if ($out !== [] && str_starts_with($clean, '(') && str_ends_with($clean, ')')) {
                $out[count($out) - 1] .= ' ' . $clean;

                continue;
            }

            $out[] = $clean;
        }

        return $out;
    }

    /** Whether the "/" at $i has a line break next to it (spaces/tabs between allowed). */
    private static function nextToNewline(string $text, int $i): bool
    {
        for ($j = $i - 1; $j >= 0 && ($text[$j] === ' ' || $text[$j] === "\t"); $j--);
        if ($j >= 0 && $text[$j] === "\n") {
            return true;
        }

        $length = strlen($text);
        for ($j = $i + 1; $j < $length && ($text[$j] === ' ' || $text[$j] === "\t"); $j++);

        return $j < $length && $text[$j] === "\n";
    }

    /** Collapses whitespace and trims the separator debris " /,;-". */
    public static function clean(string $part): string
    {
        $part = (string) preg_replace('/\s+/u', ' ', self::normaliseSpaces($part));

        return trim($part, " /,;-\t\n");
    }

    /** NBSP and friends become plain spaces; CRLF/CR become LF. */
    public static function normaliseSpaces(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace(["\u{00A0}", "\u{2007}", "\u{202F}", "\u{2009}", "\u{200B}"], [' ', ' ', ' ', ' ', ''], $text);
    }
}
