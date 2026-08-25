<?php

declare(strict_types=1);

namespace Central\Api\Support;

/**
 * Typed reader over $_ENV / $_SERVER.
 *
 * Everything arrives from the environment as a string, so a single place that
 * knows how to turn "false" into false and "" into null keeps that coercion
 * out of the config files.
 */
final class Env
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === '') {
            return $default;
        }

        return trim((string) $value);
    }

    /**
     * Same as get(), but blows up at boot when the value is missing.
     * Use for anything the application cannot safely default (secrets, DSNs).
     */
    public static function mustGet(string $key): string
    {
        $value = self::get($key);

        if ($value === null) {
            throw new \RuntimeException(
                sprintf('Required environment variable "%s" is not set. See .env.example.', $key)
            );
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null ? $default : (int) $value;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key);

        return $value === null ? $default : (float) $value;
    }

    /**
     * Reads a comma-separated variable into a trimmed, non-empty list.
     *
     * @param list<string> $default
     *
     * @return list<string>
     */
    public static function list(string $key, array $default = []): array
    {
        $value = self::get($key);

        if ($value === null) {
            return array_values($default);
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $v) => $v !== ''));
    }
}
