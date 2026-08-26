<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Proctoring;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\ProctoringLevel;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Proctoring\ProctoringSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/assessments/{id}/proctoring
 * PUT /v1/assessments/{id}/proctoring
 *
 * Requires `proctoring.configure`, which course authors do not hold by
 * default: turning on recording means deciding to record learners, and that is
 * a different decision from writing a quiz.
 */
final class ConfigureProctoringAction extends ProctoringAction
{
    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringConfigure);

        $tenantId = $this->mustBeEntitled($request);
        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        $stored = $this->proctoring->settingsFor($tenantId, $assessment->id);
        $level = $stored['level'] ?? $assessment->proctoringLevel;
        $settings = $stored['settings'] ?? ProctoringSettings::forLevel($level);

        return Json::ok($response, $this->payload($level, $settings, $tenantId));
    }

    /**
     * @param array<string, string> $args
     */
    public function save(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ProctoringConfigure);

        $tenantId = $this->mustBeEntitled($request);
        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        if (!$assessment->status->isEditable()) {
            // Changing what is monitored while people are sitting it would
            // apply different rules to different learners on one exam.
            throw new ConflictingState(
                'A published assessment cannot have its proctoring changed. Move it back to draft first.',
                'assessment_not_editable',
            );
        }

        $body = $this->body($request);
        $rawLevel = strtolower(trim((string) ($body['level'] ?? '')));
        $level = ProctoringLevel::tryFrom($rawLevel);

        if ($level === null) {
            throw ValidationFailed::fromArray([
                'level' => 'Choose one of: ' . implode(', ', array_map(
                    static fn (ProctoringLevel $l): string => $l->value,
                    ProctoringLevel::cases(),
                )),
            ]);
        }

        $overrides = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        $settings = ProctoringSettings::fromInput($level, $overrides);

        $this->proctoring->saveSettings($tenantId, $assessment->id, $level, $settings);

        return Json::ok($response, $this->payload($level, $settings, $tenantId));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ProctoringLevel $level, ProctoringSettings $settings, string $tenantId): array
    {
        return [
            'level'      => $level->value,
            'levelLabel' => $level->label(),
            'enabled'    => $level->isEnabled(),
            'settings'   => $settings->toArray(),
            // What the learner's browser will be asked for, so the author can
            // see what they are imposing before they impose it.
            'requiredPermissions' => $settings->requiredPermissions(),
            'capturesRecordings'  => $settings->capturesRecordings(),
            'capturesPersonalData' => $level->capturesPersonalData(),
            'presets' => $this->proctoring->presetsFor($tenantId),
        ];
    }
}
