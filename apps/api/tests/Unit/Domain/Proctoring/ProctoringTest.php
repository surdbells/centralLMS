<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Proctoring;

use Central\Api\Domain\Assessment\ProctoringLevel;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Proctoring\ModuleEntitlements;
use Central\Api\Domain\Proctoring\ProctoringSettings;
use Central\Api\Domain\Proctoring\TrustScore;
use Central\Api\Domain\Proctoring\ViolationSeverity;
use Central\Api\Domain\Proctoring\ViolationType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProctoringSettings::class)]
#[CoversClass(TrustScore::class)]
#[CoversClass(ViolationType::class)]
#[CoversClass(ViolationSeverity::class)]
#[CoversClass(ModuleEntitlements::class)]
final class ProctoringTest extends TestCase
{
    // --- settings ---------------------------------------------------------

    #[Test]
    public function the_none_level_monitors_nothing(): void
    {
        $settings = ProctoringSettings::forLevel(ProctoringLevel::None);

        self::assertFalse($settings->preventTabSwitch);
        self::assertFalse($settings->requireWebcam);
        self::assertFalse($settings->capturesRecordings());
        self::assertSame([], $settings->requiredPermissions());
    }

    #[Test]
    public function basic_locks_the_browser_but_watches_nobody(): void
    {
        $settings = ProctoringSettings::forLevel(ProctoringLevel::Basic);

        self::assertTrue($settings->preventTabSwitch);
        self::assertTrue($settings->requireFullscreen);
        self::assertFalse($settings->requireWebcam);
        self::assertFalse($settings->capturesRecordings());
    }

    #[Test]
    public function standard_watches_but_does_not_record(): void
    {
        $settings = ProctoringSettings::forLevel(ProctoringLevel::Standard);

        self::assertTrue($settings->requireWebcam);
        self::assertTrue($settings->faceDetection);
        self::assertTrue($settings->captureSnapshots);
        // Stills are not recordings; the distinction drives the consent
        // notice the learner is shown.
        self::assertFalse($settings->capturesRecordings());
    }

    #[Test]
    public function advanced_records_and_verifies_identity(): void
    {
        $settings = ProctoringSettings::forLevel(ProctoringLevel::Advanced);

        self::assertTrue($settings->capturesRecordings());
        self::assertTrue($settings->requireIdVerification);
        self::assertTrue($settings->requireRoomScan);
        self::assertEqualsCanonicalizing(
            ['camera', 'microphone', 'screen', 'fullscreen'],
            $settings->requiredPermissions(),
        );
    }

    #[Test]
    public function an_author_may_narrow_a_level(): void
    {
        $settings = ProctoringSettings::fromInput(ProctoringLevel::Basic, [
            'preventCopyPaste' => false,
            'maxTabSwitches'   => 10,
        ]);

        self::assertFalse($settings->preventCopyPaste);
        self::assertSame(10, $settings->maxTabSwitches);
        // Untouched fields keep the level's own default.
        self::assertTrue($settings->preventTabSwitch);
    }

    #[Test]
    public function an_empty_tab_switch_limit_means_warn_but_never_end_it(): void
    {
        // A real choice, and not the same as zero.
        $settings = ProctoringSettings::fromInput(ProctoringLevel::Basic, ['maxTabSwitches' => null]);

        self::assertNull($settings->maxTabSwitches);
    }

    #[Test]
    public function face_detection_without_a_webcam_is_refused(): void
    {
        // It would fail silently at exam time, which is the worst moment to
        // find out the monitoring was never running.
        try {
            ProctoringSettings::fromInput(ProctoringLevel::Basic, [
                'faceDetection' => true,
                'requireWebcam' => false,
            ]);
            self::fail('Expected ValidationFailed.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('faceDetection', $e->details()['violations']);
        }
    }

    #[Test]
    public function every_capability_that_needs_a_device_checks_for_it(): void
    {
        $cases = [
            ['captureSnapshots' => true, 'requireWebcam' => false],
            ['recordWebcam' => true, 'requireWebcam' => false],
            ['audioDetection' => true, 'requireMicrophone' => false],
            ['recordAudio' => true, 'requireMicrophone' => false],
            ['requireIdVerification' => true, 'requireWebcam' => false],
        ];

        foreach ($cases as $overrides) {
            $this->expectNotToPerformAssertions();

            try {
                ProctoringSettings::fromInput(ProctoringLevel::Basic, $overrides);
                self::fail('Expected ' . array_key_first($overrides) . ' to be refused.');
            } catch (ValidationFailed) {
                // Expected.
            }
        }
    }

    #[Test]
    public function flagging_faces_needs_face_detection(): void
    {
        $this->expectException(ValidationFailed::class);

        ProctoringSettings::fromInput(ProctoringLevel::Basic, [
            'requireWebcam'     => true,
            'faceDetection'     => false,
            'flagMultipleFaces' => true,
        ]);
    }

    #[Test]
    public function out_of_range_numbers_are_refused(): void
    {
        $this->expectException(ValidationFailed::class);

        ProctoringSettings::fromInput(ProctoringLevel::Basic, ['snapshotQuality' => 500]);
    }

    #[Test]
    public function a_stored_row_that_no_longer_validates_falls_back_rather_than_failing(): void
    {
        // Written before a rule existed. The author sees the level's defaults
        // and can re-save; the read does not blow up.
        $settings = ProctoringSettings::fromStored(ProctoringLevel::Standard, [
            'faceDetection' => true,
            'requireWebcam' => false,
        ]);

        self::assertTrue($settings->requireWebcam);
    }

    #[Test]
    public function unknown_keys_are_ignored_rather_than_losing_the_save(): void
    {
        $settings = ProctoringSettings::fromInput(ProctoringLevel::Basic, [
            'somethingFromAFutureVersion' => true,
        ]);

        self::assertTrue($settings->preventTabSwitch);
    }

    // --- violation weighting ---------------------------------------------

    #[Test]
    public function a_second_person_present_is_critical_and_looking_away_is_not(): void
    {
        // Treating these alike produces a score nobody can interpret.
        self::assertSame(ViolationSeverity::Critical, ViolationType::DifferentPerson->defaultSeverity());
        self::assertSame(ViolationSeverity::Critical, ViolationType::MultipleFaces->defaultSeverity());
        self::assertSame(ViolationSeverity::Low, ViolationType::LookingAway->defaultSeverity());
    }

    #[Test]
    public function only_critical_events_end_a_sitting(): void
    {
        self::assertTrue(ViolationSeverity::Critical->endsTheSitting());
        self::assertFalse(ViolationSeverity::High->endsTheSitting());
        self::assertFalse(ViolationSeverity::Medium->endsTheSitting());
        self::assertFalse(ViolationSeverity::Low->endsTheSitting());
    }

    #[Test]
    public function four_minor_events_do_not_add_up_to_one_critical(): void
    {
        self::assertLessThan(
            ViolationSeverity::Critical->weight(),
            ViolationSeverity::Low->weight() * 4,
        );
        self::assertLessThan(
            ViolationSeverity::Critical->weight(),
            ViolationSeverity::High->weight() * 2,
        );
    }

    #[Test]
    public function the_learner_is_told_about_what_they_can_fix_and_not_about_what_they_would_hide(): void
    {
        // Telling them the camera saw a second person invites them to fix the
        // evidence rather than the behaviour.
        self::assertTrue(ViolationType::TabSwitch->warnsTheLearner());
        self::assertTrue(ViolationType::FullscreenExit->warnsTheLearner());
        self::assertFalse(ViolationType::DifferentPerson->warnsTheLearner());
        self::assertFalse(ViolationType::PhoneDetected->warnsTheLearner());
    }

    #[Test]
    public function every_violation_type_has_a_severity(): void
    {
        foreach (ViolationType::cases() as $type) {
            self::assertInstanceOf(ViolationSeverity::class, $type->defaultSeverity(), $type->value);
        }
    }

    // --- trust score ------------------------------------------------------

    /**
     * @param list<array{ViolationSeverity, string}> $events
     */
    private static function trust(array $events): TrustScore
    {
        return TrustScore::fromViolations(array_map(
            static fn (array $e): array => [
                'severity'     => $e[0],
                'score'        => $e[0]->weight(),
                'reviewStatus' => $e[1],
            ],
            $events,
        ));
    }

    #[Test]
    public function a_clean_sitting_scores_one_hundred(): void
    {
        $trust = self::trust([]);

        self::assertSame(100.0, $trust->score);
        self::assertSame('clear', $trust->band());
        self::assertFalse($trust->needsReview());
    }

    #[Test]
    public function each_event_costs_its_own_weight(): void
    {
        $trust = self::trust([
            [ViolationSeverity::Low, 'pending'],
            [ViolationSeverity::Medium, 'pending'],
        ]);

        self::assertSame(97.5, $trust->score);
        self::assertSame(2, $trust->violationCount);
    }

    #[Test]
    public function one_critical_event_always_calls_for_review(): void
    {
        $trust = self::trust([[ViolationSeverity::Critical, 'pending']]);

        self::assertTrue($trust->hasCritical);
        self::assertTrue($trust->needsReview());
        self::assertSame('critical', $trust->band());
    }

    #[Test]
    public function an_accumulation_of_small_events_also_calls_for_review(): void
    {
        // The second, independent trigger: no single event is serious, but
        // together they are.
        $trust = self::trust(array_fill(0, 16, [ViolationSeverity::Medium, 'pending']));

        self::assertFalse($trust->hasCritical);
        self::assertTrue($trust->needsReview());
    }

    #[Test]
    public function a_false_positive_stops_counting(): void
    {
        // A reviewer who says it did not happen has said it did not happen;
        // continuing to count it would make review pointless.
        $trust = self::trust([
            [ViolationSeverity::Critical, 'false_positive'],
            [ViolationSeverity::Low, 'pending'],
        ]);

        self::assertSame(99.5, $trust->score);
        self::assertFalse($trust->hasCritical);
        self::assertSame(1, $trust->violationCount);
    }

    #[Test]
    public function a_confirmed_event_still_counts(): void
    {
        $trust = self::trust([[ViolationSeverity::High, 'valid']]);

        self::assertSame(92.0, $trust->score);
        self::assertSame(1, $trust->violationCount);
    }

    #[Test]
    public function the_score_never_goes_below_zero(): void
    {
        // A negative number would sort below a sitting with no events at all.
        $trust = self::trust(array_fill(0, 20, [ViolationSeverity::Critical, 'pending']));

        self::assertSame(0.0, $trust->score);
    }

    // --- entitlement ------------------------------------------------------

    #[Test]
    public function an_absent_entitlement_is_not_a_licence(): void
    {
        // Defaulting the other way would turn a failed read into a licence.
        self::assertFalse(ModuleEntitlements::none()->has(ModuleEntitlements::PROCTORING));
        self::assertFalse(ModuleEntitlements::fromStored([])->has(ModuleEntitlements::PROCTORING));
        self::assertFalse(
            ModuleEntitlements::fromStored(['proctoring' => false])->has(ModuleEntitlements::PROCTORING),
        );
    }

    #[Test]
    public function an_entitlement_is_honoured_however_it_was_stored(): void
    {
        foreach ([true, 'true', 1, '1'] as $value) {
            self::assertTrue(
                ModuleEntitlements::fromStored(['proctoring' => $value])->has(ModuleEntitlements::PROCTORING),
                var_export($value, true),
            );
        }
    }
}
