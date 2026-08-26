<?php

declare(strict_types=1);

namespace Central\Api\Domain\Settings;

use Central\Api\Domain\Gamification\PointReason;

/**
 * One tenant's settings, defaults already filled in.
 *
 * Callers ask for a value and get one. There is no "did they configure this"
 * question to forget, which is what `getSetting($category, $key, $default)`
 * left to every call site — and the default passed in differed from the one in
 * `$defaultSettings` in several of them.
 */
final readonly class TenantSettings
{
    /**
     * @param array<string, string|int|bool> $values every declared key, resolved
     * @param array<string, bool>            $overridden which of them the tenant set
     */
    private function __construct(
        public string $tenantId,
        private array $values,
        private array $overridden,
    ) {
    }

    /**
     * @param array<string, string> $stored raw overrides, key => value
     */
    public static function resolve(string $tenantId, array $stored, SettingCatalogue $catalogue): self
    {
        $values     = [];
        $overridden = [];

        foreach ($catalogue->all() as $definition) {
            $key = $definition->key->value;

            if (array_key_exists($key, $stored)) {
                $values[$key]     = $definition->parse($stored[$key]);
                $overridden[$key] = true;

                continue;
            }

            $values[$key]     = $definition->default;
            $overridden[$key] = false;
        }

        return new self($tenantId, $values, $overridden);
    }

    public function get(SettingKey $key): string|int|bool
    {
        return $this->values[$key->value] ?? throw new \LogicException(
            sprintf('Setting "%s" was not resolved.', $key->value),
        );
    }

    public function bool(SettingKey $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(SettingKey $key): int
    {
        return (int) $this->get($key);
    }

    public function string(SettingKey $key): string
    {
        return (string) $this->get($key);
    }

    public function isOverridden(SettingKey $key): bool
    {
        return $this->overridden[$key->value] ?? false;
    }

    /**
     * What a point award is worth here.
     *
     * The one place the gamification settings meet the reason enum, so
     * {@see PointReason::defaultPoints()} stays the fallback rather than
     * becoming a second opinion.
     */
    public function pointsFor(PointReason $reason): int
    {
        $key = match ($reason) {
            PointReason::LessonCompleted   => SettingKey::PointsPerLesson,
            PointReason::CourseCompleted   => SettingKey::PointsPerCourse,
            PointReason::AssessmentPassed  => SettingKey::PointsPerAssessment,
            PointReason::CertificateEarned => SettingKey::PointsPerCertificate,

            // Not configurable, and deliberately not offered.
            //
            // A manual award carries the amount the person granting it typed;
            // defaulting it from a setting would quietly overwrite that.
            // Attendance and streaks have no setting because nothing grants
            // them yet — attendance would need the ILT register to call the
            // Awarder, and a streak needs something to notice one. A setting
            // for a value nothing reads is a form field that lies.
            PointReason::Manual,
            PointReason::SessionAttended,
            PointReason::Streak            => null,
        };

        return $key === null ? $reason->defaultPoints() : $this->int($key);
    }

    /**
     * @return array<string, string|int|bool>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Grouped for the settings screen, with each value's definition beside it.
     *
     * @return array<string, mixed>
     */
    public function toArray(SettingCatalogue $catalogue): array
    {
        $categories = [];

        foreach (SettingCategory::cases() as $category) {
            $settings = [];

            foreach ($catalogue->inCategory($category) as $definition) {
                $settings[] = [
                    ...$definition->toArray(),
                    'value'      => $this->values[$definition->key->value],
                    // So a screen can show "changed from the default" without
                    // comparing values itself and getting the types wrong.
                    'isCustom'   => $this->isOverridden($definition->key),
                ];
            }

            $categories[] = [
                'category'    => $category->value,
                'label'       => $category->label(),
                'description' => $category->description(),
                'settings'    => $settings,
            ];
        }

        return ['tenantId' => $this->tenantId, 'categories' => $categories];
    }
}
