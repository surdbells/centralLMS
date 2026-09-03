<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Library;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Library\ConvertDocumentToPdf;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Library\ConversionStatus;
use Central\Api\Domain\Library\DigitalLibraryItem;
use Central\Api\Domain\Library\DigitalLibraryRepository;
use Central\Api\Domain\Media\MediaKind;
use Central\Api\Domain\Media\UploadRequest;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Managing the digital library: the whole shelf, and putting things on it.
 *
 * Everything here needs `library.manage`. Uploading stores the file and, for an
 * Office document, hands a conversion to the worker so it can be shown inline;
 * the audience is stored as a rule, not resolved to people, so it follows the
 * organisation. The file itself is never edited — a new file is a new item —
 * only its title, description and audience.
 */
final class ManageLibraryAction
{
    /** The audiences a file may be shared with. A subset of AudienceTarget. */
    private const TARGETS = ['all', 'role', 'department', 'branch', 'team'];

    public function __construct(
        private readonly DigitalLibraryRepository $library,
        private readonly MediaStorage $storage,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /** The whole library, for management. */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::LibraryManage);

        [$page, $perPage] = $this->paging($request);
        $result = $this->library->listAll(
            $this->tenantId($request),
            $this->str($request->getQueryParams()['search'] ?? null),
            $page,
            $perPage,
        );

        return Json::page(
            $response,
            array_map(static fn (DigitalLibraryItem $i): array => $i->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::LibraryManage);

        $tenantId = $this->tenantId($request);
        $body     = $this->body($request);
        $upload   = $this->file($request);

        $checked = $this->accept($upload, $body);
        [$targetType, $criteria] = $this->parseAudience($body);

        $key = $checked->storageKey($tenantId, bin2hex(random_bytes(16)));
        $stored = $this->storage->store($upload, $key);

        // A document is not viewable inline until it is a PDF, so it waits on
        // the worker; everything else is ready as it lands.
        $needsConversion = $checked->kind === MediaKind::Document;

        $item = $this->library->create(new DigitalLibraryItem(
            id: null,
            tenantId: $tenantId,
            kind: $checked->kind,
            title: $checked->title,
            originalFilename: $checked->originalFilename,
            storageKey: $key,
            fileSize: $stored['size'],
            mimeType: $checked->mimeType,
            extension: $checked->extension,
            targetType: $targetType,
            targetCriteria: $criteria,
            description: $this->textOrNull($body['description'] ?? null),
            pageCount: isset($body['pageCount']) ? (int) $body['pageCount'] : null,
            durationSeconds: isset($body['durationSeconds']) ? (float) $body['durationSeconds'] : null,
            conversionStatus: $needsConversion ? ConversionStatus::Pending : ConversionStatus::NotRequired,
            sha256: $stored['sha256'],
            uploadedBy: $caller->id,
        ));

        if ($needsConversion && $item->id !== null) {
            $this->bus->dispatch(new ConvertDocumentToPdf($tenantId, $item->id));
        }

        return Json::created($response, $item->toArray(), '/v1/library/' . $item->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::LibraryManage);

        $tenantId = $this->tenantId($request);
        $body     = $this->body($request);

        $title = $this->str($body['title'] ?? null);
        if ($title === null) {
            throw ValidationFailed::fromArray(['title' => 'Give the file a title.']);
        }

        [$targetType, $criteria] = $this->parseAudience($body);

        $item = $this->library->updateDetails(
            $tenantId,
            $args['id'],
            $title,
            $this->textOrNull($body['description'] ?? null),
            $targetType,
            $criteria,
        );

        if ($item === null) {
            throw new EntityNotFound('Library item', $args['id']);
        }

        return Json::ok($response, $item->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::LibraryManage);

        $tenantId = $this->tenantId($request);
        $item = $this->library->find($tenantId, $args['id']);

        if ($item === null || !$this->library->softDelete($tenantId, $args['id'])) {
            throw new EntityNotFound('Library item', $args['id']);
        }

        // The bytes go with the row — both the original and any converted PDF.
        // Keys not shared with another item; a library file is not versioned.
        $keys = array_filter([$item->storageKey, $item->convertedPdfKey]);
        if ($keys !== []) {
            $this->storage->remove(...array_values($keys));
        }

        return Json::noContent($response);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Validates the upload and rejects what the library does not take. SCORM is
     * course machinery, not a shared document, and has no place here.
     *
     * @param array<string, mixed> $body
     */
    private function accept(UploadedFileInterface $upload, array $body): UploadRequest
    {
        $violations = [];

        $checked = UploadRequest::check(
            originalFilename: (string) ($upload->getClientFilename() ?? ''),
            size: (int) ($upload->getSize() ?? 0),
            mimeType: (string) ($upload->getClientMediaType() ?? ''),
            violations: $violations,
            title: isset($body['title']) ? (string) $body['title'] : null,
        );

        if ($checked === null) {
            throw ValidationFailed::fromArray($violations);
        }

        if ($checked->kind === MediaKind::Scorm) {
            throw ValidationFailed::fromArray(['file' => 'SCORM packages are not shared through the library.']);
        }

        return $checked;
    }

    /**
     * The audience from the body: a target and, unless it is everyone, the ids
     * it names, stored under the canonical criteria key for that target.
     *
     * @param  array<string, mixed> $body
     * @return array{0: AudienceTarget, 1: array<string, list<string>>}
     */
    private function parseAudience(array $body): array
    {
        $typeValue = $this->str($body['targetType'] ?? null) ?? 'all';
        if (!in_array($typeValue, self::TARGETS, true)) {
            throw ValidationFailed::fromArray(['targetType' => 'Choose who the file is shared with.']);
        }

        $target = AudienceTarget::from($typeValue);

        if ($target === AudienceTarget::All) {
            return [$target, []];
        }

        $ids = $this->ids($body['targetIds'] ?? null);
        if ($ids === []) {
            throw ValidationFailed::fromArray([
                'targetIds' => sprintf('Choose at least one %s to share with.', $target->noun()),
            ]);
        }

        // The resolver reads the first of a target's criteria keys.
        $key = $target->criteriaKeys()[0];

        return [$target, [$key => $ids]];
    }

    /**
     * The ids out of the body, whether sent as a JSON array, a repeated field,
     * or a comma-separated string.
     *
     * @return list<string>
     */
    private function ids(mixed $value): array
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : $value;
        }

        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== '');
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            $id = trim((string) $v);
            if ($id !== '') {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    private function file(ServerRequestInterface $request): UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? (is_array($files) ? reset($files) : null);

        if (!$file instanceof UploadedFileInterface) {
            throw ValidationFailed::fromArray(['file' => 'Attach a file to share.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => 'The file did not arrive in one piece.']);
        }

        return $file;
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);
        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::LibraryManage->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array{int, int}
     */
    private function paging(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            max(1, (int) ($query['page'] ?? 1)),
            max(1, min(200, (int) ($query['perPage'] ?? 50))),
        ];
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

    private function textOrNull(mixed $value): ?string
    {
        return $this->str($value);
    }
}
