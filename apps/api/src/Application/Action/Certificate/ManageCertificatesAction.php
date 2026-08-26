<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Certificate;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Certificate\Certificate;
use Central\Api\Domain\Certificate\CertificateStatus;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Issued certificates.
 *
 * GET   /v1/certificates                 the register
 * GET   /v1/me/certificates              the holder's own
 * GET   /v1/certificates/{id}
 * GET   /v1/certificates/{id}/render     the HTML, ready for PDF
 * POST  /v1/certificates/{id}/revoke
 * POST  /v1/certificates/{id}/reinstate
 * GET   /v1/certificates/{id}/history
 */
final class ManageCertificatesAction extends CertificateAction
{
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // The register is other people's names and results, so it needs the
        // issuing permission rather than merely being logged in.
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $listing = $this->listing($request);
        $query = $request->getQueryParams();

        $result = $this->certificates->paginate(
            $this->tenantId($request),
            $listing['page'],
            $listing['perPage'],
            $listing['search'],
            isset($query['status']) && $query['status'] !== ''
                ? CertificateStatus::tryFrom((string) $query['status'])
                : null,
            isset($query['userId']) ? (string) $query['userId'] : null,
        );

        return Json::page(
            $response,
            array_map(static fn (Certificate $c): array => $c->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * What the signed-in learner holds.
     *
     * Scoped to the caller by construction — no id in the path, so there is
     * nothing to tamper with.
     */
    public function mine(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, array_map(
            static fn (Certificate $c): array => $c->toArray(),
            $this->certificates->forLearner($this->tenantId($request), $this->userId($request)),
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $certificate = $this->requireVisible($request, $args['id'] ?? '');

        return Json::ok($response, $certificate->toArray([
            'verificationUrl' => $this->verificationUrl($request, $certificate),
        ]));
    }

    /**
     * The rendered certificate, as HTML ready to be printed to PDF.
     *
     * Returned rather than a PDF because generating one belongs behind a queue
     * — dompdf on a web request is how a report endpoint takes down a server.
     * The URL of the finished file comes back through `recordPdf()`.
     *
     * @param array<string, string> $args
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $certificate = $this->requireVisible($request, $args['id'] ?? '');

        $template = $certificate->templateId === null
            ? $this->certificates->findDefaultTemplate($tenantId)
            : $this->certificates->findTemplate($tenantId, $certificate->templateId);

        if ($template === null) {
            throw new ConflictingState(
                'The template this certificate was issued against is no longer available.',
                'template_missing',
            );
        }

        // Recorded here: the holder asking to see it is the download, and the
        // counter on the register is a COUNT over these rows.
        $this->certificates->log($tenantId, $certificate->id, 'downloaded', $this->userId($request));

        return Json::ok($response, [
            'certificateId' => $certificate->id,
            'orientation'   => $template->orientation,
            'pageSize'      => $template->pageSize,
            'styles'        => $template->stylesCss,
            'html'          => $this->renderer->render(
                $template->bodyHtml,
                $certificate->placeholders($this->verificationUrl($request, $certificate)),
            ),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function revoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? '';

        $reason = trim((string) ($this->body($request)['reason'] ?? ''));

        if ($reason === '') {
            // Withdrawing someone's qualification is not something to do
            // silently: if the holder disputes it, the reason is the answer.
            throw ValidationFailed::fromArray([
                'reason' => 'Say why this certificate is being withdrawn.',
            ]);
        }

        if ($this->certificates->find($tenantId, $id) === null) {
            throw new EntityNotFound('Certificate', $id);
        }

        $revoked = $this->certificates->revoke($tenantId, $id, $reason, $this->userId($request));

        return $revoked === null
            ? throw new ConflictingState('This certificate is already withdrawn.', 'already_revoked')
            : Json::ok($response, $revoked->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function reinstate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? '';

        if ($this->certificates->find($tenantId, $id) === null) {
            throw new EntityNotFound('Certificate', $id);
        }

        $reinstated = $this->certificates->reinstate($tenantId, $id, $this->userId($request));

        return $reinstated === null
            ? throw new ConflictingState('This certificate is not withdrawn.', 'not_revoked')
            : Json::ok($response, $reinstated->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function history(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $tenantId = $this->tenantId($request);
        $certificate = $this->certificates->find($tenantId, $args['id'] ?? '')
            ?? throw new EntityNotFound('Certificate', $args['id'] ?? '');

        return Json::ok($response, [
            'certificateId' => $certificate->id,
            'number'        => $certificate->identity->number,
            'events'        => $this->certificates->history($tenantId, $certificate->id),
        ]);
    }
}
