<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Certificate;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Certificate\CertificateRenderer;
use Central\Api\Domain\Certificate\CertificateTemplate;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTML certificates are rendered from.
 *
 * GET    /v1/certificate-templates
 * POST   /v1/certificate-templates
 * GET    /v1/certificate-templates/{id}
 * PUT    /v1/certificate-templates/{id}
 * DELETE /v1/certificate-templates/{id}
 * POST   /v1/certificate-templates/{id}/preview
 */
final class ManageTemplatesAction extends CertificateAction
{
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $listing = $this->listing($request);

        $result = $this->certificates->paginateTemplates(
            $this->tenantId($request),
            $listing['page'],
            $listing['perPage'],
            $listing['search'],
        );

        return Json::page(
            $response,
            array_map(static fn (CertificateTemplate $t): array => $t->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $template = $this->certificates->findTemplate($this->tenantId($request), $args['id'] ?? '')
            ?? throw new EntityNotFound('Template', $args['id'] ?? '');

        return Json::ok($response, [
            ...$template->toArray(),
            // Sent alongside so an editor can offer them rather than the
            // author guessing at the names.
            'placeholders' => CertificateRenderer::PLACEHOLDERS,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function save(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? null;

        $existing = $id === null
            ? null
            : ($this->certificates->findTemplate($tenantId, $id) ?? throw new EntityNotFound('Template', $id));

        $template = CertificateTemplate::fromInput(
            $existing->id ?? '',
            $tenantId,
            $existing === null ? $this->body($request) : [...$existing->toArray(), ...$this->body($request)],
            $this->renderer,
        );

        if ($this->certificates->templateHasName($tenantId, $template->name, $existing?->id)) {
            throw new ConflictingState(
                sprintf('A template called "%s" already exists.', $template->name),
                'duplicate_name',
            );
        }

        $saved = $this->certificates->saveTemplate($template);

        return $existing === null
            ? Json::created($response, $saved->toArray(), '/v1/certificate-templates/' . $saved->id)
            : Json::ok($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? '';

        $template = $this->certificates->findTemplate($tenantId, $id)
            ?? throw new EntityNotFound('Template', $id);

        if ($template->isDefault) {
            // Removing the default would leave issuance with no template to
            // fall back on, and the failure would show up at issue time.
            throw new ConflictingState(
                'Make another template the default before removing this one.',
                'template_is_default',
            );
        }

        return $this->certificates->deleteTemplate($tenantId, $id)
            ? Json::noContent($response)
            : throw new EntityNotFound('Template', $id);
    }

    /**
     * Renders a template against sample values.
     *
     * So an author can see what it looks like without issuing a certificate to
     * a real person to find out.
     *
     * @param array<string, string> $args
     */
    public function preview(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CertificateIssue);

        $template = $this->certificates->findTemplate($this->tenantId($request), $args['id'] ?? '')
            ?? throw new EntityNotFound('Template', $args['id'] ?? '');

        return Json::ok($response, [
            'orientation' => $template->orientation,
            'pageSize'    => $template->pageSize,
            'styles'      => $template->stylesCss,
            'html'        => $this->renderer->render($template->bodyHtml, [
                'learner_name'       => 'Ada Lovelace',
                'title'              => 'Workplace Fire Safety',
                'certificate_number' => 'SAMPLE-2026-00001',
                'verification_code'  => 'SAMPLE1234',
                'verification_url'   => 'https://example.test/verify/sample',
                'issue_date'         => (new \DateTimeImmutable())->format('j F Y'),
                'valid_from'         => (new \DateTimeImmutable())->format('j F Y'),
                'valid_until'        => (new \DateTimeImmutable('+1 year'))->format('j F Y'),
                'score'              => 46,
                'percentage'         => '92%',
                'grade'              => 'A',
                'organisation'       => 'Sample Organisation',
                'source'             => 'Course completion',
                'custom'             => is_array($this->body($request)['custom'] ?? null)
                    ? $this->body($request)['custom']
                    : [],
            ]),
        ]);
    }
}
