<?php

declare(strict_types=1);

namespace Central\Api\Domain\Proctoring;

use Central\Api\Domain\Assessment\ProctoringLevel;
use Central\Api\Domain\Exception\ValidationFailed;

/**
 * What a proctored sitting monitors.
 *
 * Stored as one `jsonb` column rather than thirty-five boolean columns: these
 * are configuration, read as a unit when a sitting starts, never filtered on
 * and never aggregated. The legacy schema already conceded the point — its
 * presets table held the same fields as JSON — so one shape serves both.
 *
 * The meaning therefore lives here, and this class is the only thing that may
 * construct one.
 */
final readonly class ProctoringSettings
{
    private function __construct(
        // Browser lockdown.
        public bool $preventTabSwitch = true,
        public bool $preventCopyPaste = true,
        public bool $preventRightClick = true,
        public bool $requireFullscreen = true,
        public bool $detectDevTools = true,
        public bool $windowBlurDetection = true,
        public ?int $maxTabSwitches = 3,
        public bool $warnOnTabSwitch = true,
        // Camera.
        public bool $requireWebcam = false,
        public int $webcamCheckIntervalSeconds = 30,
        public bool $faceDetection = false,
        public bool $flagMultipleFaces = false,
        public bool $flagNoFace = false,
        // Stills.
        public bool $captureSnapshots = false,
        public int $snapshotIntervalSeconds = 60,
        public bool $snapshotOnViolation = true,
        public int $snapshotQuality = 80,
        // Microphone.
        public bool $requireMicrophone = false,
        public bool $audioDetection = false,
        // Recording.
        public bool $recordScreen = false,
        public bool $recordWebcam = false,
        public bool $recordAudio = false,
        public int $maxRecordingSizeMb = 500,
        // Identity.
        public bool $requireIdVerification = false,
        public bool $requireRoomScan = false,
    ) {
    }

    /**
     * The settings a level implies, before any tuning.
     *
     * A level is the promise made to whoever commissioned the exam; these are
     * what it takes to keep it. An author may narrow the details, but
     * `forLevel()` is what "standard proctoring" means by default.
     */
    public static function forLevel(ProctoringLevel $level): self
    {
        return match ($level) {
            ProctoringLevel::None => new self(
                preventTabSwitch: false,
                preventCopyPaste: false,
                preventRightClick: false,
                requireFullscreen: false,
                detectDevTools: false,
                windowBlurDetection: false,
                maxTabSwitches: null,
                warnOnTabSwitch: false,
            ),

            // Lockdown only: nothing is watched, nothing is recorded.
            ProctoringLevel::Basic => new self(),

            // Watched, and stills kept.
            ProctoringLevel::Standard => new self(
                requireWebcam: true,
                faceDetection: true,
                flagMultipleFaces: true,
                flagNoFace: true,
                captureSnapshots: true,
            ),

            // Everything, including identity and recordings.
            ProctoringLevel::Advanced => new self(
                requireWebcam: true,
                faceDetection: true,
                flagMultipleFaces: true,
                flagNoFace: true,
                captureSnapshots: true,
                requireMicrophone: true,
                audioDetection: true,
                recordScreen: true,
                recordWebcam: true,
                recordAudio: true,
                requireIdVerification: true,
                requireRoomScan: true,
            ),
        };
    }

    /**
     * Builds settings for a level from an author's overrides.
     *
     * Unknown keys are ignored rather than rejected, so a client sending a
     * field this version does not know about does not lose the save.
     *
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(ProctoringLevel $level, array $input): self
    {
        $defaults = self::forLevel($level);
        $violations = [];

        $bool = static fn (string $key, bool $fallback): bool => array_key_exists($key, $input)
            ? filter_var($input[$key], FILTER_VALIDATE_BOOL)
            : $fallback;

        $int = static function (string $key, int $fallback, int $min, int $max) use ($input, &$violations): int {
            if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
                return $fallback;
            }

            if (!is_numeric($input[$key])) {
                $violations[$key] = 'Enter a number.';

                return $fallback;
            }

            $value = (int) $input[$key];

            if ($value < $min || $value > $max) {
                $violations[$key] = sprintf('Choose a value between %d and %d.', $min, $max);
            }

            return $value;
        };

        $maxTabSwitches = $defaults->maxTabSwitches;

        if (array_key_exists('maxTabSwitches', $input)) {
            $raw = $input['maxTabSwitches'];

            // Null means "warn but never auto-submit", which is a real choice
            // and not the same as zero.
            if ($raw === null || $raw === '') {
                $maxTabSwitches = null;
            } elseif (!is_numeric($raw) || (int) $raw < 0) {
                $violations['maxTabSwitches'] = 'Enter how many switches are tolerated, or leave it empty.';
            } else {
                $maxTabSwitches = (int) $raw;
            }
        }

        $settings = new self(
            preventTabSwitch: $bool('preventTabSwitch', $defaults->preventTabSwitch),
            preventCopyPaste: $bool('preventCopyPaste', $defaults->preventCopyPaste),
            preventRightClick: $bool('preventRightClick', $defaults->preventRightClick),
            requireFullscreen: $bool('requireFullscreen', $defaults->requireFullscreen),
            detectDevTools: $bool('detectDevTools', $defaults->detectDevTools),
            windowBlurDetection: $bool('windowBlurDetection', $defaults->windowBlurDetection),
            maxTabSwitches: $maxTabSwitches,
            warnOnTabSwitch: $bool('warnOnTabSwitch', $defaults->warnOnTabSwitch),
            requireWebcam: $bool('requireWebcam', $defaults->requireWebcam),
            webcamCheckIntervalSeconds: $int('webcamCheckIntervalSeconds', $defaults->webcamCheckIntervalSeconds, 5, 600),
            faceDetection: $bool('faceDetection', $defaults->faceDetection),
            flagMultipleFaces: $bool('flagMultipleFaces', $defaults->flagMultipleFaces),
            flagNoFace: $bool('flagNoFace', $defaults->flagNoFace),
            captureSnapshots: $bool('captureSnapshots', $defaults->captureSnapshots),
            snapshotIntervalSeconds: $int('snapshotIntervalSeconds', $defaults->snapshotIntervalSeconds, 5, 3600),
            snapshotOnViolation: $bool('snapshotOnViolation', $defaults->snapshotOnViolation),
            snapshotQuality: $int('snapshotQuality', $defaults->snapshotQuality, 1, 100),
            requireMicrophone: $bool('requireMicrophone', $defaults->requireMicrophone),
            audioDetection: $bool('audioDetection', $defaults->audioDetection),
            recordScreen: $bool('recordScreen', $defaults->recordScreen),
            recordWebcam: $bool('recordWebcam', $defaults->recordWebcam),
            recordAudio: $bool('recordAudio', $defaults->recordAudio),
            maxRecordingSizeMb: $int('maxRecordingSizeMb', $defaults->maxRecordingSizeMb, 1, 10000),
            requireIdVerification: $bool('requireIdVerification', $defaults->requireIdVerification),
            requireRoomScan: $bool('requireRoomScan', $defaults->requireRoomScan),
        );

        // Settings that cannot do what they claim. Each of these would fail
        // silently at exam time, which is the worst moment to find out.
        if ($settings->faceDetection && !$settings->requireWebcam) {
            $violations['faceDetection'] = 'Face detection needs the webcam to be required.';
        }

        if ($settings->captureSnapshots && !$settings->requireWebcam) {
            $violations['captureSnapshots'] = 'Capturing snapshots needs the webcam to be required.';
        }

        if ($settings->recordWebcam && !$settings->requireWebcam) {
            $violations['recordWebcam'] = 'Recording the webcam needs the webcam to be required.';
        }

        if ($settings->audioDetection && !$settings->requireMicrophone) {
            $violations['audioDetection'] = 'Listening for noise needs the microphone to be required.';
        }

        if ($settings->recordAudio && !$settings->requireMicrophone) {
            $violations['recordAudio'] = 'Recording audio needs the microphone to be required.';
        }

        if (($settings->flagMultipleFaces || $settings->flagNoFace) && !$settings->faceDetection) {
            $violations['faceDetection'] = 'Flagging faces needs face detection turned on.';
        }

        if ($settings->requireIdVerification && !$settings->requireWebcam) {
            $violations['requireIdVerification'] = 'Verifying identity needs the webcam to be required.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function fromStored(ProctoringLevel $level, array $stored): self
    {
        try {
            return self::fromInput($level, $stored);
        } catch (ValidationFailed) {
            // A stored row that no longer validates — written before a rule
            // was added — falls back to the level's own defaults rather than
            // failing the read. The author sees the defaults and can re-save.
            return self::forLevel($level);
        }
    }

    /**
     * What the learner's browser must be granted before starting.
     *
     * Returned as a list so the client can ask for everything in one prompt
     * rather than interrupting the sitting device by device.
     *
     * @return list<string>
     */
    public function requiredPermissions(): array
    {
        $needed = [];

        if ($this->requireWebcam) {
            $needed[] = 'camera';
        }

        if ($this->requireMicrophone) {
            $needed[] = 'microphone';
        }

        if ($this->recordScreen) {
            $needed[] = 'screen';
        }

        if ($this->requireFullscreen) {
            $needed[] = 'fullscreen';
        }

        return $needed;
    }

    /**
     * Whether anything here captures a recording of the learner.
     *
     * Drives the consent notice: being watched and being recorded are
     * different things to agree to.
     */
    public function capturesRecordings(): bool
    {
        return $this->recordScreen || $this->recordWebcam || $this->recordAudio;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preventTabSwitch'    => $this->preventTabSwitch,
            'preventCopyPaste'    => $this->preventCopyPaste,
            'preventRightClick'   => $this->preventRightClick,
            'requireFullscreen'   => $this->requireFullscreen,
            'detectDevTools'      => $this->detectDevTools,
            'windowBlurDetection' => $this->windowBlurDetection,
            'maxTabSwitches'      => $this->maxTabSwitches,
            'warnOnTabSwitch'     => $this->warnOnTabSwitch,
            'requireWebcam'       => $this->requireWebcam,
            'webcamCheckIntervalSeconds' => $this->webcamCheckIntervalSeconds,
            'faceDetection'       => $this->faceDetection,
            'flagMultipleFaces'   => $this->flagMultipleFaces,
            'flagNoFace'          => $this->flagNoFace,
            'captureSnapshots'    => $this->captureSnapshots,
            'snapshotIntervalSeconds' => $this->snapshotIntervalSeconds,
            'snapshotOnViolation' => $this->snapshotOnViolation,
            'snapshotQuality'     => $this->snapshotQuality,
            'requireMicrophone'   => $this->requireMicrophone,
            'audioDetection'      => $this->audioDetection,
            'recordScreen'        => $this->recordScreen,
            'recordWebcam'        => $this->recordWebcam,
            'recordAudio'         => $this->recordAudio,
            'maxRecordingSizeMb'  => $this->maxRecordingSizeMb,
            'requireIdVerification' => $this->requireIdVerification,
            'requireRoomScan'     => $this->requireRoomScan,
        ];
    }
}
