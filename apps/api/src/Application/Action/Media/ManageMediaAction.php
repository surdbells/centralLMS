<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Media;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Media\MediaFilters;
use Central\Api\Domain\Media\MediaItem;
use Central\Api\Domain\Media\MediaKind;
use Central\Api\Domain\Media\MediaRepository;
use Central\Api\Domain\Media\UploadRequest;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The media library.
 *
 * GET    /v1/media
 * GET    /v1/media/statistics
 * GET    /v1/media/{id}
 * GET    /v1/media/{id}/versions
 * POST   /v1/media
 * POST   /v1/media/{id}/versions
 * PUT    /v1/media/{id}
 * DELETE /v1/media/{id}
 *
 * Four things the legacy did not do:
 *
 *  - **Every query is scoped to the caller's tenant.** `getMediaById()` matched
 *    on the id alone, and answered with a redirect to a signed storage URL —
 *    so a guessed id fetched another organisation's file, and the link kept
 *    working for whoever it was passed to.
 *  - **What is accepted is decided by the extension**, not by the `Content-Type`
 *    the client sends, which the client chooses.
 *  - **The tenant's storage limit is enforced.** `tenants.storage_limit_mb` has
 *    been on the table since the first schema and was never once read.
 *  - **A new version is numbered inside the transaction**, so two people
 *    replacing the same file at once do not both write version three.
 */
final class ManageMediaAction extends MediaAction
{
    public function __construct(
        MediaRepository $media,
        private readonly MediaStorage $storage,
        private readonly Connection $connection,
    ) {
        parent::__construct($media);
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::MediaView);

        $violations = [];
        $filters    = MediaFilters::fromQuery($request->getQueryParams(), $violations);

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $listing = $this->listing($request);

        $result = $this->media->paginate(
            $this->tenantId($request),
            $filters,
            $listing['page'],
            $listing['perPage'],
        );

        return Json::page(
            $response,
            array_map(static fn (MediaItem $m): array => $m->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * What the library holds, and what it is costing.
     */
    public function statistics(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::MediaView);

        $tenantId   = $this->tenantId($request);
        $statistics = $this->media->statistics($tenantId);
        $limit      = $this->storageLimitBytes($tenantId);

        return Json::ok($response, [
            ...$statistics,
            'limitBytes'     => $limit,
            'bytesRemaining' => $limit === null ? null : max(0, $limit - $statistics['bytesUsed']),
            'accepts'        => $this->acceptedTypes(),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::MediaView);

        return Json::ok($response, $this->require($request, $args['id'])->toArray());
    }

    /**
     * Every version of a file, newest first.
     *
     * @param array<string, string> $args
     */
    public function versions(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::MediaView);

        $item = $this->require($request, $args['id']);

        // Asked of any version, answers about the whole chain: somebody
        // looking at version two wants the list, not an empty one.
        $versions = $this->media->versionsOf(
            $this->tenantId($request),
            (string) ($item->rootMediaId ?? $item->id),
        );

        return Json::ok($response, array_map(static fn (MediaItem $m): array => $m->toArray(), $versions));
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::MediaManage);

        $tenantId = $this->tenantId($request);
        $body     = $this->body($request);
        $upload   = $this->file($request);

        $checked = $this->accept($upload, $body, $tenantId);

        $item = $this->media->create($this->assemble($checked, $upload, $request, $body, $tenantId));

        return Json::created($response, $item->toArray(), '/v1/media/' . $item->id);
    }

    /**
     * Replaces a file, keeping the old one.
     *
     * The previous version stays readable: a course that pointed at it is not
     * broken by somebody uploading a revision, and "what did the policy say in
     * March" is a question compliance actually asks.
     *
     * @param array<string, string> $args
     */
    public function uploadVersion(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::MediaManage);

        $tenantId = $this->tenantId($request);
        $existing = $this->require($request, $args['id']);
        $body     = $this->body($request);
        $upload   = $this->file($request);

        // A new version of a video has to be a video. Changing the kind
        // mid-chain would leave a lesson that embeds a player unable to render
        // what it is now pointing at.
        $checked = $this->accept($upload, [...$body, 'kind' => $existing->kind->value], $tenantId);

        $item = $this->media->createVersion(
            $this->assemble($checked, $upload, $request, $body, $tenantId, $existing),
            (string) $existing->id,
        );

        if ($item === null) {
            // The row was there a moment ago, so this is a delete that landed
            // in between. The stored bytes have no row to belong to.
            $this->storage->remove($checked['storageKey']);

            throw new ConflictingState('That file was removed while the new version was uploading.');
        }

        return Json::created($response, $item->toArray(), '/v1/media/' . $item->id);
    }

    /**
     * Title, description, course and version notes. Never the file.
     *
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::MediaManage);

        $existing = $this->require($request, $args['id']);
        $body     = $this->body($request);

        $title = trim((string) ($body['title'] ?? $existing->title));

        if ($title === '') {
            throw ValidationFailed::fromArray(['title' => 'Give the file a name.']);
        }

        $courseId = array_key_exists('courseId', $body)
            ? $this->uuidOrNull($body['courseId'])
            : $existing->courseId;

        $updated = $this->media->update(new MediaItem(
            id: $existing->id,
            tenantId: $existing->tenantId,
            kind: $existing->kind,
            title: mb_substr($title, 0, 255),
            originalFilename: $existing->originalFilename,
            storageKey: $existing->storageKey,
            fileSize: $existing->fileSize,
            mimeType: $existing->mimeType,
            extension: $existing->extension,
            description: $this->textOrNull($body['description'] ?? $existing->description),
            metadata: is_array($body['metadata'] ?? null) ? $body['metadata'] : $existing->metadata,
            versionLabel: $this->textOrNull($body['versionLabel'] ?? $existing->versionLabel),
            versionNotes: $this->textOrNull($body['versionNotes'] ?? $existing->versionNotes),
            courseId: $courseId,
        ));

        if ($updated === null) {
            throw new ConflictingState('That file was removed while it was being edited.');
        }

        return Json::ok($response, $updated->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::MediaManage);

        $tenantId    = $this->tenantId($request);
        $allVersions = filter_var($request->getQueryParams()['allVersions'] ?? false, FILTER_VALIDATE_BOOL);

        $this->require($request, $args['id']);

        // The rows go first. Removing the bytes and then failing to write the
        // row leaves a library entry pointing at nothing, which is worse than
        // a stored file nothing points at.
        $orphaned = $this->media->softDelete($tenantId, $args['id'], $allVersions);

        $this->storage->remove(...$orphaned);

        return Json::noContent($response);
    }

    // --- upload plumbing --------------------------------------------------

    /**
     * The uploaded part, whatever the form field was called.
     */
    private function file(ServerRequestInterface $request): UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        // `file` by convention, but whatever arrived if the form called it
        // something else — the field name is not worth a 422.
        $file = $files['file'] ?? reset($files);

        if (!$file instanceof UploadedFileInterface) {
            throw ValidationFailed::fromArray(['file' => 'Attach a file to upload.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => $this->uploadError($file->getError())]);
        }

        return $file;
    }

    /**
     * Checks the file, then stores it.
     *
     * @param  array<string, mixed> $body
     * @return array{request: UploadRequest, storageKey: string, size: int, sha256: string}
     */
    private function accept(UploadedFileInterface $upload, array $body, string $tenantId): array
    {
        $violations = [];

        $checked = UploadRequest::check(
            originalFilename: (string) ($upload->getClientFilename() ?? ''),
            size: (int) ($upload->getSize() ?? 0),
            mimeType: (string) ($upload->getClientMediaType() ?? ''),
            violations: $violations,
            title: isset($body['title']) ? (string) $body['title'] : null,
            declaredKind: isset($body['kind']) ? (string) $body['kind'] : null,
        );

        if ($checked === null) {
            throw ValidationFailed::fromArray($violations);
        }

        $this->mustFitInQuota($tenantId, $checked->size);

        $key = $checked->storageKey($tenantId, bin2hex(random_bytes(16)));

        $stored = $this->storage->store($upload, $key);

        // The size is now measured rather than claimed, so the quota is worth
        // checking again — this is the number that will be billed.
        if ($stored['size'] !== $checked->size) {
            try {
                $this->mustFitInQuota($tenantId, $stored['size']);
            } catch (ConflictingState $e) {
                $this->storage->remove($key);

                throw $e;
            }
        }

        return [
            'request'    => $checked,
            'storageKey' => $key,
            'size'       => $stored['size'],
            'sha256'     => $stored['sha256'],
        ];
    }

    /**
     * @param array{request: UploadRequest, storageKey: string, size: int, sha256: string} $stored
     * @param array<string, mixed>                                                         $body
     */
    private function assemble(
        array $stored,
        UploadedFileInterface $upload,
        ServerRequestInterface $request,
        array $body,
        string $tenantId,
        ?MediaItem $previous = null,
    ): MediaItem {
        $checked = $stored['request'];

        return new MediaItem(
            id: null,
            tenantId: $tenantId,
            kind: $checked->kind,
            title: $checked->title,
            originalFilename: $checked->originalFilename,
            storageKey: $stored['storageKey'],
            fileSize: $stored['size'],
            mimeType: $checked->mimeType,
            extension: $checked->extension,
            description: $this->textOrNull($body['description'] ?? $previous?->description),
            uploadedBy: $this->caller($request)->id,
            // Dimensions and duration come from the client, which is the only
            // thing that has decoded the file: nothing here transcodes. They
            // are display hints, and nothing depends on them being right.
            durationSeconds: isset($body['durationSeconds']) ? (float) $body['durationSeconds'] : null,
            width: isset($body['width']) ? (int) $body['width'] : null,
            height: isset($body['height']) ? (int) $body['height'] : null,
            pageCount: isset($body['pageCount']) ? (int) $body['pageCount'] : null,
            scormVersion: $checked->kind === MediaKind::Scorm
                ? $this->textOrNull($body['scormVersion'] ?? $previous->scormVersion ?? '1.2')
                : null,
            launchUrl: $checked->kind === MediaKind::Scorm
                ? $this->textOrNull($body['launchUrl'] ?? $previous->launchUrl ?? 'index_lms.html')
                : null,
            metadata: is_array($body['metadata'] ?? null) ? $body['metadata'] : [],
            sha256: $stored['sha256'],
            versionLabel: $this->textOrNull($body['versionLabel'] ?? null),
            versionNotes: $this->textOrNull($body['versionNotes'] ?? null),
            courseId: array_key_exists('courseId', $body)
                ? $this->uuidOrNull($body['courseId'])
                : $previous?->courseId,
        );
    }

    /**
     * The tenant's storage allowance, in bytes, or null for unmetered.
     */
    private function storageLimitBytes(string $tenantId): ?int
    {
        $limit = $this->connection->fetchOne(
            'SELECT storage_limit_mb FROM tenants WHERE id = :id',
            ['id' => $tenantId],
        );

        return $limit === null || $limit === false ? null : ((int) $limit) * 1_048_576;
    }

    private function mustFitInQuota(string $tenantId, int $incoming): void
    {
        $limit = $this->storageLimitBytes($tenantId);

        if ($limit === null) {
            return;
        }

        $used = $this->media->statistics($tenantId)['bytesUsed'];

        if ($used + $incoming > $limit) {
            throw new ConflictingState(
                sprintf(
                    'This organisation has used %s of its %s storage allowance, and the file does not fit.',
                    $this->humanSize($used),
                    $this->humanSize($limit),
                ),
                'storage_limit_reached',
            );
        }
    }

    /**
     * What the upload form should offer, straight from the rules that decide.
     *
     * @return array<string, array{extensions: list<string>, maxSize: int}>
     */
    private function acceptedTypes(): array
    {
        $accepts = [];

        foreach (MediaKind::cases() as $kind) {
            $accepts[$kind->value] = [
                'extensions' => $kind->extensions(),
                'maxSize'    => $kind->maxSize(),
            ];
        }

        return $accepts;
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server accepts.',
            UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE                        => 'Attach a file to upload.',
            default                                   => 'The upload could not be stored.',
        };
    }

    private function humanSize(int $bytes): string
    {
        foreach (['GB' => 1_073_741_824, 'MB' => 1_048_576, 'KB' => 1024] as $unit => $step) {
            if ($bytes >= $step) {
                return rtrim(rtrim(number_format($bytes / $step, 1, '.', ''), '0'), '.') . ' ' . $unit;
            }
        }

        return $bytes . ' bytes';
    }

    private function textOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^[0-9a-f-]{36}$/i', $value) === 1 ? $value : null;
    }
}
