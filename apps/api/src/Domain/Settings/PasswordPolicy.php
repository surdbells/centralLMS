<?php

declare(strict_types=1);

namespace Central\Api\Domain\Settings;

/**
 * What a password has to look like here.
 *
 * The legacy declared five of these keys and enforced none of them: nothing in
 * the backend reads `password_min_length` or any of its neighbours, and
 * `change_password.php` accepts a single character. So the settings screen
 * offered a security policy and the system had none.
 *
 * Pure, and built from a tenant's settings rather than reading them, so the
 * rules can be tested without a database and stated without one either — the
 * description below is what a sign-up form should print, generated from the
 * same values that do the checking.
 */
final readonly class PasswordPolicy
{
    private function __construct(
        public int $minimumLength,
        public bool $requiresUppercase,
        public bool $requiresLowercase,
        public bool $requiresNumber,
        public bool $requiresSymbol,
    ) {
    }

    public static function from(TenantSettings $settings): self
    {
        return new self(
            minimumLength: $settings->int(SettingKey::PasswordMinLength),
            requiresUppercase: $settings->bool(SettingKey::PasswordRequireUppercase),
            requiresLowercase: $settings->bool(SettingKey::PasswordRequireLowercase),
            requiresNumber: $settings->bool(SettingKey::PasswordRequireNumber),
            requiresSymbol: $settings->bool(SettingKey::PasswordRequireSpecial),
        );
    }

    /**
     * The rules that apply when no tenant is known.
     *
     * The same rule the API already enforced before any of this was
     * configurable — twelve characters with a letter and a number — restated
     * in the legacy's vocabulary. A capital is not required by default,
     * because length does more for strength than character classes do and a
     * passphrase somebody remembers beats eight characters of punctuation
     * written on a card.
     */
    public static function default(): self
    {
        return new self(
            minimumLength: 12,
            requiresUppercase: false,
            requiresLowercase: true,
            requiresNumber: true,
            requiresSymbol: false,
        );
    }

    /**
     * Why this password is not acceptable, or an empty list if it is.
     *
     * All of the reasons, not the first: telling somebody their password is
     * too short, then that it needs a capital, then that it needs a digit,
     * three attempts running, is how a password field is abandoned.
     *
     * @return list<string>
     */
    public function reasonsToRefuse(string $password): array
    {
        $reasons = [];

        // Counted in characters, not bytes. A passphrase in Arabic is not
        // three times longer than one in English.
        if (mb_strlen($password) < $this->minimumLength) {
            $reasons[] = sprintf('Use at least %d characters.', $this->minimumLength);
        }

        if ($this->requiresUppercase && preg_match('/\p{Lu}/u', $password) !== 1) {
            $reasons[] = 'Include a capital letter.';
        }

        if ($this->requiresLowercase && preg_match('/\p{Ll}/u', $password) !== 1) {
            $reasons[] = 'Include a small letter.';
        }

        if ($this->requiresNumber && preg_match('/\p{Nd}/u', $password) !== 1) {
            $reasons[] = 'Include a number.';
        }

        if ($this->requiresSymbol && preg_match('/[^\p{L}\p{Nd}]/u', $password) !== 1) {
            $reasons[] = 'Include a symbol.';
        }

        return $reasons;
    }

    public function accepts(string $password): bool
    {
        return $this->reasonsToRefuse($password) === [];
    }

    /**
     * The rules in a sentence, for the form to show before somebody types.
     */
    public function describe(): string
    {
        $needs = [];

        if ($this->requiresUppercase) {
            $needs[] = 'a capital letter';
        }

        if ($this->requiresLowercase) {
            $needs[] = 'a small letter';
        }

        if ($this->requiresNumber) {
            $needs[] = 'a number';
        }

        if ($this->requiresSymbol) {
            $needs[] = 'a symbol';
        }

        $sentence = sprintf('At least %d characters', $this->minimumLength);

        if ($needs === []) {
            return $sentence . '.';
        }

        $last = array_pop($needs);

        return $needs === []
            ? sprintf('%s, including %s.', $sentence, $last)
            : sprintf('%s, including %s and %s.', $sentence, implode(', ', $needs), $last);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'minimumLength'     => $this->minimumLength,
            'requiresUppercase' => $this->requiresUppercase,
            'requiresLowercase' => $this->requiresLowercase,
            'requiresNumber'    => $this->requiresNumber,
            'requiresSymbol'    => $this->requiresSymbol,
            'description'       => $this->describe(),
        ];
    }
}
