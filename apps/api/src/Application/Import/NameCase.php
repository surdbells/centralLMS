<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

/**
 * Turns the single "Names" cell of a staff workbook into a first and a last
 * name fit for an account.
 *
 * Workbooks are typed in capitals as often as not ("ELOZINO OLANIYAN"); an
 * account called that shouts at its holder on every screen and every
 * certificate. A word written entirely in capitals is therefore re-cased —
 * each part of a hyphenated or apostrophised word capitalised on its own
 * ("OMOTOSHO-OBORO" → "Omotosho-Oboro", "O'NEIL" → "O'Neil") — while a word
 * somebody already cased ("McDonald", "deGraft") is kept exactly as written.
 *
 * The first word is the first name and everything after it the last name, so a
 * trailing initial ("Ada Test C.") stays with the surname. A name in capitals
 * or with a comma is put in the address's given.surname order when the address
 * names it ("TEST ADA" + "ada.test@" → "Ada Test"); failing that, "TEST, Ada"
 * (surname first, after a comma) is read as "Ada Test". A leading
 * courtesy title ("Mr", "Engr.") is dropped when at least two names remain.
 *
 * Pure: no database.
 */
final class NameCase
{
    /** Courtesy titles dropped from the front of a name. */
    private const TITLES = ['mr', 'mrs', 'ms', 'miss', 'dr', 'engr', 'prof', 'chief', 'barr', 'arc', 'pst', 'pastor', 'sir', 'dame', 'hon'];

    /**
     * @return array{firstName: string, lastName: string}
     */
    public static function split(string $name, ?string $email = null): array
    {
        $tokens = self::tokens($name, $email);

        if ($tokens === []) {
            return ['firstName' => '', 'lastName' => ''];
        }

        $first = array_shift($tokens);

        return ['firstName' => $first, 'lastName' => implode(' ', $tokens)];
    }

    /**
     * The whole name, cleaned and re-cased, as one string.
     */
    public static function proper(string $name, ?string $email = null): string
    {
        return implode(' ', self::tokens($name, $email));
    }

    /**
     * One word, re-cased only when it was written entirely in capitals.
     */
    public static function word(string $word): string
    {
        $letters = (string) preg_replace('/[^\p{L}]+/u', '', $word);

        // Nothing to re-case, already cased by a person, or a lone initial ("C.").
        if ($letters === '' || mb_strtoupper($letters) !== $letters || mb_strlen($letters) < 2) {
            return $word;
        }

        $lower = mb_strtolower($word);

        // Capitalise the first letter of the word and of every part after a
        // hyphen or an apostrophe (straight or curly).
        return (string) preg_replace_callback(
            "/(^|[-'\u{2019}])(\p{Ll})/u",
            static fn (array $m): string => $m[1] . mb_strtoupper($m[2]),
            $lower,
        );
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $name, ?string $email = null): array
    {
        $name = str_replace("\u{00A0}", ' ', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " \t\n\r\0\x0B,;");

        if ($name === '') {
            return [];
        }

        // Registers typed in capitals often put the surname first, with or
        // without a comma ("OBI ADA", "OBI, CHUKWU ADA"). The organisation's
        // addresses are given.surname, so when the address's first part is
        // found among the words, that run of words is the first name.
        $letters = (string) preg_replace('/[^\p{L}]+/u', '', $name);
        $capital = $letters !== '' && mb_strtoupper($letters) === $letters;

        if ($capital || str_contains($name, ',')) {
            // Register order first ("OBI, CHUKWU ADA" → "CHUKWU ADA OBI"), so
            // what is left after the given name moves keeps the surname last.
            $halves = explode(',', $name);
            $plain  = count($halves) === 2 && trim($halves[0]) !== '' && trim($halves[1]) !== ''
                ? $halves[1] . ' ' . $halves[0]
                : str_replace(',', ' ', $name);

            $oriented = self::orientByEmail(self::stripTitles(self::words($plain)), $email);

            if ($oriented !== null) {
                return self::finish($oriented);
            }
        }

        // Otherwise "SURNAME, Given Names" — the register style some sheets
        // use — is read as "Given Names SURNAME", so the given name comes
        // first. When the address plainly starts with the part before the
        // comma ("ada.test@" for "Ada, Test") the comma was only a separator
        // and the order is kept.
        $halves = explode(',', $name);

        if (count($halves) === 2 && trim($halves[0]) !== '' && trim($halves[1]) !== '') {
            $before = trim($halves[0]);
            $after  = trim($halves[1]);
            $local  = mb_strtolower((string) strstr((string) $email . '@', '@', true));
            $lead   = static fn (string $part): string => mb_strtolower((string) preg_replace('/[^\p{L}].*$/u', '', $part));

            $keep = $local !== ''
                && $lead($before) !== ''
                && str_starts_with($local, $lead($before))
                && !str_starts_with($local, $lead($after));

            $name = $keep ? $before . ' ' . $after : $after . ' ' . $before;
        }

        return self::finish(self::words($name));
    }

    /**
     * True when some word of the name is one of the parts of the address
     * before the "@" (split on ".", "_" and "-"; letters only, any case) — the
     * cheap check that a name and an address belong to the same person.
     */
    public static function matchesEmail(?string $name, string $email): bool
    {
        return self::emailScore($name, $email) > 0;
    }

    /**
     * How many distinct parts of the address the name's words account for:
     * "Ada Test" scores 2 against "ada.test@", "Bola Ada Obi" only 1 — so of
     * two spellings, the one the address bears out best can be preferred.
     */
    public static function emailScore(?string $name, string $email): int
    {
        $parts = [];

        foreach (preg_split('/[._\-]+/', self::local($email)) ?: [] as $part) {
            $part = self::letters($part);

            if (mb_strlen($part) >= 2) {
                $parts[$part] = false;
            }
        }

        foreach (self::words(str_replace([',', '-'], ' ', (string) $name)) as $word) {
            $word = self::letters($word);

            if (isset($parts[$word])) {
                $parts[$word] = true;
            }
        }

        return count(array_filter($parts));
    }

    /**
     * The words reordered so the run matching the first part of the address
     * leads ("OSUYA JOHN PEDRO" + "john-pedro.osuya@" → "JOHN-PEDRO OSUYA"),
     * joined with a hyphen when the address joins them so. Null when the
     * address has no given.surname shape or its first part is not among the
     * words — the caller then falls back to its other rules.
     *
     * @param list<string> $tokens
     *
     * @return ?list<string>
     */
    public static function orientByEmail(array $tokens, ?string $email): ?array
    {
        $parts = array_values(array_filter(
            preg_split('/[._]+/', self::local((string) $email)) ?: [],
            static fn (string $p): bool => $p !== '',
        ));

        if (count($parts) < 2) {
            return null;
        }

        $target = self::letters($parts[0]);

        if (mb_strlen($target) < 2) {
            return null;
        }

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $run = '';

            for ($j = $i; $j < $count; $j++) {
                $run .= self::letters($tokens[$j]);

                if ($run === $target) {
                    $given = array_slice($tokens, $i, $j - $i + 1);

                    if (str_contains($parts[0], '-')) {
                        $given = [implode('-', $given)];
                    }

                    return [...$given, ...array_slice($tokens, 0, $i), ...array_slice($tokens, $j + 1)];
                }

                if (!str_starts_with($target, $run)) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        return array_values(array_filter(
            explode(' ', (string) preg_replace('/\s+/u', ' ', trim($name))),
            static fn (string $t): bool => trim($t, ',;') !== '',
        ));
    }

    /**
     * "Engr. Ada Obi" → "Ada Obi"; a bare "Dr Obi" keeps its title rather
     * than leaving a one-word name nobody can be created with.
     *
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private static function stripTitles(array $tokens): array
    {
        while (count($tokens) > 2 && in_array(strtolower(rtrim($tokens[0], '.')), self::TITLES, true)) {
            array_shift($tokens);
        }

        return $tokens;
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private static function finish(array $tokens): array
    {
        return array_map(static fn (string $t): string => self::word(trim($t, ',;')), self::stripTitles($tokens));
    }

    private static function local(string $email): string
    {
        return mb_strtolower((string) strstr(trim($email) . '@', '@', true));
    }

    private static function letters(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}]+/u', '', $text));
    }
}
