<?php

declare(strict_types=1);

namespace Central\Api\Domain\Proctoring;

/**
 * What the proctor observed.
 *
 * The legacy enum had 33 values in one flat list. They are kept — each one
 * came from a real detector — but grouped by what raised them, because the
 * response differs: a tab switch is the learner's own action, a second face in
 * frame may not be.
 */
enum ViolationType: string
{
    // --- the learner's own actions ---------------------------------------
    case TabSwitch = 'tab_switch';
    case WindowBlur = 'window_blur';
    case FullscreenExit = 'fullscreen_exit';
    case CopyAttempt = 'copy_attempt';
    case PasteAttempt = 'paste_attempt';
    case RightClick = 'right_click';
    case PrintScreen = 'print_screen';
    case DevToolsOpen = 'dev_tools_open';
    case BrowserResize = 'browser_resize';

    // --- what the camera saw ---------------------------------------------
    case NoFaceDetected = 'no_face_detected';
    case MultipleFaces = 'multiple_faces';
    case FaceNotCentered = 'face_not_centered';
    case FaceTooFar = 'face_too_far';
    case FaceTooClose = 'face_too_close';
    case FaceObscured = 'face_obscured';
    case DifferentPerson = 'different_person';
    case LookingAway = 'looking_away';
    case SuspiciousMovement = 'suspicious_movement';

    // --- what the microphone heard ---------------------------------------
    case VoiceDetected = 'voice_detected';
    case MultipleVoices = 'multiple_voices';
    case ExcessiveNoise = 'excessive_noise';

    // --- objects in view --------------------------------------------------
    case PhoneDetected = 'phone_detected';
    case BookDetected = 'book_detected';
    case AnotherScreen = 'another_screen';
    case SuspiciousObject = 'suspicious_object';

    // --- the session itself ----------------------------------------------
    case IdleTimeout = 'idle_timeout';
    case RapidAnswers = 'rapid_answers';
    case ConnectionLost = 'connection_lost';
    case VpnDetected = 'vpn_detected';
    case ProxyDetected = 'proxy_detected';

    // --- raised by a person or a model ------------------------------------
    case ManualFlag = 'manual_flag';
    case AiAnomaly = 'ai_anomaly';
    case Other = 'other';

    public static function fromLegacy(string $value): self
    {
        return self::from(strtolower(trim($value)));
    }

    /**
     * How much this costs the trust score by default.
     *
     * Deliberately not uniform. Treating "looked away" and "a different person
     * is sitting the exam" as equally serious produces a score nobody can
     * interpret, and a review queue nobody reads.
     */
    public function defaultSeverity(): ViolationSeverity
    {
        return match ($this) {
            // Unambiguous substitution or a second person present.
            self::DifferentPerson,
            self::MultipleFaces,
            self::MultipleVoices => ViolationSeverity::Critical,

            // Deliberate circumvention.
            self::DevToolsOpen,
            self::PhoneDetected,
            self::AnotherScreen,
            self::VpnDetected,
            self::ProxyDetected,
            self::BookDetected => ViolationSeverity::High,

            // Could be innocent, could not be.
            self::TabSwitch,
            self::FullscreenExit,
            self::NoFaceDetected,
            self::FaceObscured,
            self::CopyAttempt,
            self::PasteAttempt,
            self::PrintScreen,
            self::VoiceDetected,
            self::SuspiciousObject,
            self::RapidAnswers,
            self::ManualFlag => ViolationSeverity::Medium,

            // Noise, in every sense. Recorded for the record, weighted near
            // zero, because flagging these as serious is how a proctoring
            // report becomes something nobody trusts.
            default => ViolationSeverity::Low,
        };
    }

    /**
     * Whether the learner is told at the time.
     *
     * Silent recording of the learner's own actions is pointless — they can
     * stop doing it if told — while telling them the camera thinks someone
     * else is present invites them to fix the evidence.
     */
    public function warnsTheLearner(): bool
    {
        return match ($this) {
            self::TabSwitch,
            self::WindowBlur,
            self::FullscreenExit,
            self::CopyAttempt,
            self::PasteAttempt,
            self::RightClick,
            self::PrintScreen,
            self::BrowserResize,
            self::NoFaceDetected,
            self::FaceNotCentered,
            self::FaceTooFar,
            self::FaceTooClose => true,

            default => false,
        };
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
