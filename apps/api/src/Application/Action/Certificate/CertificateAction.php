<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Certificate;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Certificate\Certificate;
use Central\Api\Domain\Certificate\CertificateRenderer;
use Central\Api\Domain\Certificate\CertificateRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the authenticated certificate endpoints.
 */
abstract class CertificateAction
{
    public function __construct(
        protected readonly CertificateRepository $certificates,
        protected readonly CertificateRenderer $renderer,
    ) {
    }

    final protected function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    /**
     * @throws AccessDenied
     */
    final protected function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::CertificateIssue->value);
        }

        return $user->tenantId;
    }

    final protected function userId(ServerRequestInterface $request): string
    {
        return $this->caller($request)->id;
    }

    /**
     * A certificate the caller is allowed to see.
     *
     * A learner may read their own; anyone else needs the issuing permission.
     * Without this a certificate id would be a way to read a colleague's
     * results, since the id is in every download link.
     *
     * @throws EntityNotFound
     * @throws AccessDenied
     */
    final protected function requireVisible(ServerRequestInterface $request, string $id): Certificate
    {
        $certificate = $this->certificates->find($this->tenantId($request), $id)
            ?? throw new EntityNotFound('Certificate', $id);

        if (
            $certificate->userId !== $this->userId($request)
            && !$this->caller($request)->can(Permission::CertificateIssue)
        ) {
            throw new AccessDenied(Permission::CertificateIssue->value);
        }

        return $certificate;
    }

    /**
     * @return array<string, mixed>
     */
    final protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * @return array{page: int, perPage: int, search: ?string}
     */
    final protected function listing(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            'page'    => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, min(100, (int) ($query['perPage'] ?? 20))),
            'search'  => isset($query['search']) ? (string) $query['search'] : null,
        ];
    }

    /**
     * The public URL a verifier follows.
     *
     * Built from the request so a deployment behind a custom domain produces
     * links that work, rather than hard-coding a host into every certificate
     * ever issued.
     */
    final protected function verificationUrl(ServerRequestInterface $request, Certificate $certificate): string
    {
        $uri = $request->getUri();

        return sprintf(
            '%s://%s/verify/%s',
            $uri->getScheme(),
            $uri->getAuthority(),
            $certificate->identity->hash,
        );
    }
}
