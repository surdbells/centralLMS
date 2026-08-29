<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Catalog;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Catalog\CourseResource;
use Central\Api\Domain\Catalog\CourseResourceRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

/**
 * Downloadable file resources attached to a course.
 *
 * GET    /v1/courses/{courseId}/resources                 list them
 * POST   /v1/courses/{courseId}/resources                 add one (multipart)
 * GET    /v1/courses/{courseId}/resources/{id}/file       download one
 * DELETE /v1/courses/{courseId}/resources/{id}            remove one
 *
 * Any file type is accepted — a resource is reference material, not content the
 * player has to understand — so unlike a lesson it is not restricted to the
 * handful of playable formats. Reading needs `course.view`; adding and removing
 * need `course.manage`, the permission that edits the course.
 */
final class CourseResourceAction
{
    /** 100 MB — generous for a deck or a standard, a wall against a mistake. */
    private const MAX_BYTES = 104_857_600;

    public function __construct(
        private readonly CourseResourceRepository $resources,
        private readonly MediaStorage $storage,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CourseView);
        $tenantId = $this->tenantId($request);

        $items = $this->resources->forCourse($tenantId, $args['courseId'] ?? '');

        return Json::ok($response, array_map(
            static fn (CourseResource $r): array => $r->toArray(),
            $items,
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function upload(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CourseManage);
        $tenantId = $this->tenantId($request);
        $courseId = $args['courseId'] ?? '';

        $upload = $this->file($request);
        $body   = $request->getParsedBody();
        $title  = trim((string) (is_array($body) ? ($body['title'] ?? '') : ''));

        if ($title === '') {
            // Fall back to the file's own name, so a resource always has a label.
            $title = (string) ($upload->getClientFilename() ?? 'Resource');
        }

        $key = sprintf(
            'course-resources/%s/%s/%s',
            $tenantId,
            $courseId,
            bin2hex(random_bytes(16)),
        );

        $stored = $this->storage->store($upload, $key);

        if ($stored['size'] > self::MAX_BYTES) {
            // Written and then rejected: the size is measured from the bytes,
            // not the client's claim, so it is only known after the write.
            $this->storage->remove($key);
            throw ValidationFailed::fromArray(['file' => 'That file is larger than 100 MB.']);
        }

        $saved = $this->resources->add(new CourseResource(
            id: '',
            tenantId: $tenantId,
            courseId: $courseId,
            title: $title,
            originalFilename: (string) ($upload->getClientFilename() ?? 'resource'),
            storageKey: $key,
            mimeType: (string) ($upload->getClientMediaType() ?: 'application/octet-stream'),
            sizeBytes: $stored['size'],
            createdBy: $caller->id,
        ));

        return Json::created($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function download(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CourseView);
        $tenantId = $this->tenantId($request);

        $resource = $this->resources->find($tenantId, $args['id'] ?? '');

        if ($resource === null || $resource->courseId !== ($args['courseId'] ?? '')) {
            throw new EntityNotFound('Course resource', $args['id'] ?? '');
        }

        if (!$this->storage->has($resource->storageKey)) {
            throw new EntityNotFound('Course resource file', $resource->id);
        }

        $stream = new Stream($this->storage->read($resource->storageKey));

        return $response
            ->withHeader('Content-Type', $resource->mimeType)
            ->withHeader('Content-Length', (string) ($stream->getSize() ?? $resource->sizeBytes))
            ->withHeader('Cache-Control', 'private, max-age=3600')
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="' . $this->safeName($resource->originalFilename) . '"',
            )
            ->withBody($stream);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CourseManage);
        $tenantId = $this->tenantId($request);

        $key = $this->resources->remove($tenantId, $args['id'] ?? '');

        if ($key === null) {
            throw new EntityNotFound('Course resource', $args['id'] ?? '');
        }

        // The row is gone; the bytes follow, and a failure to remove them is a
        // wasted byte rather than a broken delete.
        $this->storage->remove($key);

        return Json::noContent($response);
    }

    // --- internals --------------------------------------------------------

    private function file(ServerRequestInterface $request): UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $upload = $files['file'] ?? null;

        if (!$upload instanceof UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => 'Choose a file to upload.']);
        }

        return $upload;
    }

    private function safeName(string $name): string
    {
        // Strip anything that could break out of the header or the filename.
        $clean = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'resource';

        return $clean === '' ? 'resource' : $clean;
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $caller = $this->caller($request);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::CourseView->value);
        }

        return $caller->tenantId;
    }
}
