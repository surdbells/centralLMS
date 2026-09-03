<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Saas;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Saas\TenantProvisioner;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Saas\OnboardingCodeRepository;
use Central\Api\Domain\Saas\PlanRepository;
use Central\Api\Support\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public self-service sign-up.
 *
 * The other half of the SaaS layer from the operator console: a customer,
 * with no session, creates their own organisation from the marketing site.
 * It is gated — an operator-issued code has to be presented and is spent on
 * success — and it stands the organisation up on a trial of the chosen plan,
 * with its administrator able to sign in straight away.
 *
 * Unauthenticated, so it is deliberately careful: it never says more about a
 * code than whether it works, the code is reserved atomically before anything
 * is created (and given back if creation fails), and the whole route sits
 * behind the same per-IP throttle as the rest of the public surface.
 */
final class OnboardingAction
{
    /**
     * The one thing sign-up ever says about a code it will not accept, whether
     * it is unknown, spent, inactive or expired. Keeping them indistinguishable
     * is what stops the endpoint being used to discover real issued codes.
     */
    private const INVALID_CODE = 'That code is not valid, or is no longer available.';

    /** Codes that cannot become a workspace subdomain — they mean something else. */
    private const RESERVED = [
        'www', 'api', 'app', 'admin', 'operator', 'mail', 'ftp', 'test', 'staging',
        'dev', 'demo', 'help', 'support', 'docs', 'status', 'blog', 'cdn', 'assets',
        'static', 'public', 'login', 'auth', 'billing',
    ];

    public function __construct(
        private readonly OnboardingCodeRepository $codes,
        private readonly PlanRepository $plans,
        private readonly TenantProvisioner $provisioner,
        private readonly Connection $connection,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Whether a code may be used, and — if so — which plans it unlocks.
     *
     * Says as little as it can: a code that does not exist and one that is
     * spent both come back simply not valid, and the plan allow-list is only
     * revealed for a code that actually works, so the wizard can grey out the
     * plans it does not cover.
     */
    public function validateCode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $code = $this->str($this->body($request)['code'] ?? null);
        if ($code === null) {
            return Json::ok($response, ['valid' => false, 'error' => 'Enter your onboarding code.']);
        }

        $found = $this->codes->findByCode($code);
        if ($found === null || !$found->isUsable($this->clock->now())) {
            return Json::ok($response, ['valid' => false, 'error' => self::INVALID_CODE]);
        }

        return Json::ok($response, [
            'valid'          => true,
            'description'    => $found->description,
            // null = every plan; the wizard shows all when it is null.
            'allowedPlanIds' => $found->allowedPlanIds,
        ]);
    }

    /**
     * Creates the organisation and its administrator, and spends a code use.
     */
    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $companyName = $this->str($body['companyName'] ?? null);
        $email       = $this->str($body['adminEmail'] ?? null);
        $firstName   = $this->str($body['adminFirstName'] ?? null);
        $lastName    = $this->str($body['adminLastName'] ?? null);
        $password    = is_string($body['password'] ?? null) ? $body['password'] : '';
        $planId      = $this->str($body['planId'] ?? null);
        $codeString  = $this->str($body['onboardingCode'] ?? null);

        $violations = [];
        if ($companyName === null) {
            $violations['companyName'] = 'Enter your organisation’s name.';
        }
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $violations['adminEmail'] = 'Enter a valid email address.';
        }
        if ($firstName === null) {
            $violations['adminFirstName'] = 'Enter your first name.';
        }
        if ($lastName === null) {
            $violations['adminLastName'] = 'Enter your last name.';
        }
        if (strlen($password) < 8) {
            $violations['password'] = 'Choose a password of at least 8 characters.';
        }
        if ($planId === null) {
            $violations['planId'] = 'Choose a plan.';
        }
        if ($codeString === null) {
            $violations['onboardingCode'] = 'Enter your onboarding code.';
        }
        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        // The code has to exist and be usable, right now. It says no more than
        // that — a code that does not exist and one that is spent or expired
        // both come back the same, so this cannot be used to discover which
        // guessed strings are real issued codes.
        $code = $this->codes->findByCode((string) $codeString);
        if ($code === null || !$code->isUsable($this->clock->now())) {
            throw ValidationFailed::fromArray(['onboardingCode' => self::INVALID_CODE]);
        }

        // The plan has to be on offer, and unlocked by the code.
        $plan = $this->plans->find((string) $planId);
        if ($plan === null || !$plan->isActive || !$plan->isPublic) {
            throw ValidationFailed::fromArray(['planId' => 'Choose a plan that is on offer.']);
        }
        if (!$code->allowsPlan((string) $plan->id)) {
            throw ValidationFailed::fromArray(['planId' => 'That plan is not available with this code.']);
        }

        $tenantCode = $this->resolveTenantCode(
            $this->str($body['subdomain'] ?? null) ?? (string) $companyName,
        );

        $phone   = $this->str($body['phone'] ?? null);
        $industry = $this->str($body['industry'] ?? null);
        $size    = $this->str($body['companySize'] ?? null);

        try {
            // The use is spent and the organisation created in one transaction,
            // on the one shared connection, so nothing can leave a use spent
            // without a tenant (or a tenant without a use): a failure anywhere
            // rolls both back. The conditional UPDATE inside spendUse locks the
            // row, so two sign-ups racing on the last use serialise here.
            $result = $this->connection->transactional(
                function () use ($code, $tenantCode, $companyName, $email, $firstName, $lastName, $password, $plan, $phone, $industry, $size): array {
                    if (!$this->codes->spendUse((string) $code->id)) {
                        throw ValidationFailed::fromArray(['onboardingCode' => 'This code has just been used up.']);
                    }

                    return $this->provisioner->provision(
                        name: (string) $companyName,
                        code: $tenantCode,
                        contactEmail: (string) $email,
                        adminFirstName: (string) $firstName,
                        adminLastName: (string) $lastName,
                        password: $password,
                        planId: (string) $plan->id,
                        status: 'trial',
                        contactPhone: $phone,
                        industry: $industry,
                        companySize: $size,
                    );
                },
            );
        } catch (UniqueConstraintViolationException) {
            // The workspace name raced with another sign-up; the whole
            // transaction rolled back, so the use was handed back with it.
            throw ValidationFailed::fromArray(['companyName' => 'That workspace name was just taken — try another.']);
        }

        return Json::created($response, [
            'tenant' => [
                'id'         => $result['tenantId'],
                'name'       => $companyName,
                'code'       => $tenantCode,
                'status'     => 'trial',
                'adminEmail' => $email,
                'plan'       => ['id' => $plan->id, 'name' => $plan->name],
            ],
        ], '/v1/tenants/' . $result['tenantId']);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * A free, valid workspace code from a base string (a subdomain or the
     * organisation's name). Slugged, kept off the reserved list, and made
     * unique against the organisations that already exist.
     */
    private function resolveTenantCode(string $base): string
    {
        $slug = strtolower($base);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 40);
        $slug = trim($slug, '-');

        if (strlen($slug) < 3 || in_array($slug, self::RESERVED, true)) {
            $slug = ($slug === '' ? 'org' : $slug) . '-' . random_int(100, 999);
        }

        $candidate = $slug;
        // A handful of tries with a growing random tail; collisions are rare
        // and each try is one indexed lookup.
        for ($attempt = 0; $this->tenantCodeTaken($candidate); $attempt++) {
            if ($attempt > 8) {
                $candidate = $slug . '-' . bin2hex(random_bytes(3));

                break;
            }
            $candidate = $slug . '-' . random_int(100, 9999);
        }

        return $candidate;
    }

    private function tenantCodeTaken(string $code): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM tenants WHERE lower(code) = lower(:code) AND deleted_at IS NULL',
            ['code' => $code],
        ) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
