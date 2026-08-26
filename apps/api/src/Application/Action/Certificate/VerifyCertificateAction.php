<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Certificate;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Certificate\Certificate;
use Central\Api\Domain\Certificate\CertificateIdentity;
use Central\Api\Domain\Certificate\CertificateRepository;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /verify/{hash}       from the QR code or link on the certificate
 * POST /verify             from the "check a certificate" form, by code
 *
 * **The only unauthenticated endpoints in the API**, and deliberately so: the
 * point of a certificate is that a stranger — an employer, a regulator — can
 * check it without an account here. That makes this the most exposed surface
 * in the codebase, so it is built to give away as little as possible.
 *
 * - It is outside the authenticated group, and therefore outside tenant
 *   scoping. The identifier *is* the credential.
 * - The payload is `Certificate::toPublicArray()`: the holder's name, what it
 *   is for, and whether it stands. No email address, no score, no ids.
 * - A malformed identifier is rejected before the database is touched, so the
 *   endpoint cannot be used to make the database work on request.
 * - A miss and a hit take the same shape, and a miss says only "no such
 *   certificate" — never which part was wrong.
 * - Every check is logged against the certificate, which is what lets an
 *   issuer see that one of their certificates is being verified unusually
 *   often.
 *
 * Rate limiting is the middleware's job and is applied to this group; the
 * ten-character code is short enough to be worth guessing at scale, which is
 * why the hash exists for links and the code is for people reading aloud.
 */
final class VerifyCertificateAction
{
    public function __construct(private readonly CertificateRepository $certificates)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function byHash(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $hash = trim($args['hash'] ?? '');

        // Checked before any query: the shape is cheap to verify and stops the
        // endpoint being a way to make PostgreSQL do work for free.
        if (!CertificateIdentity::looksLikeHash($hash)) {
            return $this->notFound($response);
        }

        return $this->answer($request, $response, $this->certificates->verifyByHash($hash));
    }

    public function byCode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($body['code'] ?? ''));

        if ($code === '') {
            throw ValidationFailed::fromArray(['code' => 'Enter the code printed on the certificate.']);
        }

        if (!CertificateIdentity::looksLikeCode($code)) {
            return $this->notFound($response);
        }

        return $this->answer($request, $response, $this->certificates->verifyByCode($code));
    }

    /**
     * Answers a verification, logging it against the certificate.
     */
    private function answer(
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?Certificate $certificate,
    ): ResponseInterface {
        if ($certificate === null) {
            return $this->notFound($response);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $this->certificates->log(
            $certificate->tenantId,
            $certificate->id,
            'verified',
            null,
            [
                // Whatever the verifier chose to tell us, which may be
                // nothing and may be untrue. Recorded as their claim.
                'verifierName'    => $this->text($body['verifierName'] ?? null),
                'verifierEmail'   => $this->text($body['verifierEmail'] ?? null),
                'verifierCompany' => $this->text($body['verifierCompany'] ?? null),
                'ip'              => $this->clientIp($request),
            ],
        );

        return Json::ok($response, $certificate->toPublicArray(new \DateTimeImmutable()));
    }

    /**
     * One answer for every miss.
     *
     * A 200 rather than a 404, and the same shape as a hit: a verification
     * form that returns different statuses for "no such code" and "wrong
     * length" is a way to learn the format, and one that 404s tells a scanner
     * exactly which guesses to keep.
     */
    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, [
            'found'   => false,
            'isValid' => false,
            'message' => 'No certificate matches that reference.',
        ]);
    }

    /**
     * The caller's address, for abuse investigation only.
     *
     * Taken from the connection rather than a forwarded header unless the
     * deployment says otherwise — `X-Forwarded-For` is caller-controlled, and
     * trusting it by default would let anyone write whatever they liked into
     * the audit log.
     */
    private function clientIp(ServerRequestInterface $request): ?string
    {
        $params = $request->getServerParams();
        $address = $params['REMOTE_ADDR'] ?? null;

        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false
            ? $address
            : null;
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        // Capped: this is written to an audit row by an anonymous caller.
        return $value === '' ? null : mb_substr($value, 0, 255);
    }
}
