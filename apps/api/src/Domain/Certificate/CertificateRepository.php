<?php

declare(strict_types=1);

namespace Central\Api\Domain\Certificate;

/**
 * Storage for certificate templates, issued certificates and their history.
 *
 * Most methods take a tenant id. The two that do not — `verifyByHash()` and
 * `verifyByCode()` — are the public verification path, where the caller is a
 * stranger with no tenant and the identifier itself is the credential. Those
 * two are marked and are the only untenanted reads in the codebase.
 */
interface CertificateRepository
{
    // --- templates --------------------------------------------------------

    /**
     * @return array{items: list<CertificateTemplate>, total: int}
     */
    public function paginateTemplates(string $tenantId, int $page, int $perPage, ?string $search = null): array;

    public function findTemplate(string $tenantId, string $id): ?CertificateTemplate;

    public function findDefaultTemplate(string $tenantId): ?CertificateTemplate;

    public function saveTemplate(CertificateTemplate $template): CertificateTemplate;

    public function deleteTemplate(string $tenantId, string $id): bool;

    public function templateHasName(string $tenantId, string $name, ?string $exceptId = null): bool;

    // --- issuing ----------------------------------------------------------

    /**
     * Issues a certificate, superseding any active one for the same thing.
     *
     * One transaction: the partial unique index allows only one active
     * certificate per learner per course, so the old one must move to
     * `superseded` before the new one lands, or the insert is rejected.
     *
     * @param array<string, mixed> $details
     */
    public function issue(
        string $tenantId,
        CertificateSource $source,
        string $sourceId,
        string $userId,
        array $details,
        ?string $actorId,
    ): Certificate;

    /**
     * The next sequence number for a tenant this year, for the printed number.
     */
    public function nextSequence(string $tenantId, int $year): int;

    /**
     * The tenant's short code, for the readable part of the number.
     */
    public function tenantCode(string $tenantId): string;

    /**
     * The organisation's name, as it should appear on a certificate.
     *
     * The tenant's own record, not a `company_name` setting: keeping a
     * second copy in a key/value table is how the two come to disagree.
     */
    public function organisationName(string $tenantId): ?string;

    // --- reading ----------------------------------------------------------

    public function find(string $tenantId, string $id): ?Certificate;

    /**
     * @return array{items: list<Certificate>, total: int}
     */
    public function paginate(
        string $tenantId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?CertificateStatus $status = null,
        ?string $userId = null,
    ): array;

    /**
     * Everything one learner holds, newest first.
     *
     * @return list<Certificate>
     */
    public function forLearner(string $tenantId, string $userId): array;

    /**
     * The active certificate for a learner and a thing, if there is one.
     */
    public function findForSource(
        string $tenantId,
        CertificateSource $source,
        string $sourceId,
        string $userId,
    ): ?Certificate;

    // --- the public verification path -------------------------------------

    /**
     * Looks a certificate up by the hash in a verification URL.
     *
     * Untenanted on purpose: whoever is checking has no account here, and the
     * hash is 32 random bytes, so possessing it is the authorisation.
     */
    public function verifyByHash(string $hash): ?Certificate;

    /**
     * Looks a certificate up by its short spoken code.
     *
     * Also untenanted, and weaker than the hash — a ten-character code is
     * short enough to be worth guessing at scale, which is why the action
     * behind this is rate-limited and why the payload it returns is minimal.
     */
    public function verifyByCode(string $code): ?Certificate;

    // --- lifecycle --------------------------------------------------------

    public function revoke(string $tenantId, string $id, string $reason, string $actorId): ?Certificate;

    public function reinstate(string $tenantId, string $id, string $actorId): ?Certificate;

    /**
     * Marks certificates past their date as expired.
     *
     * The status is computed on read as well — see
     * `Certificate::effectiveStatus()` — so this is housekeeping for reporting
     * rather than something correctness depends on.
     *
     * @return int how many were marked
     */
    public function expireLapsed(?string $tenantId = null): int;

    public function recordPdf(string $tenantId, string $id, string $pdfUrl): ?Certificate;

    // --- history ----------------------------------------------------------

    /**
     * @param array<string, mixed> $context
     */
    public function log(
        string $tenantId,
        string $certificateId,
        string $action,
        ?string $actorId = null,
        array $context = [],
    ): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function history(string $tenantId, string $certificateId): array;
}
