<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Stream;

/**
 * A picture attached to a question.
 *
 * POST /v1/question-images            -> stores one, returns a stable mediaUrl
 * GET  /v1/question-images/{imageId}  -> serves the bytes (header-authenticated)
 *
 * Stored outside the media library, like avatars: a question image is not a
 * library asset with a title and a version chain, and authoring one should need
 * only `assessment.manage`, not `media.manage`. The served URL carries no tenant
 * but the storage key is tenant-scoped, so a guessed id only ever resolves
 * within the caller's own tenant. Because it is header-authenticated, the
 * question form fetches it with `appAuthImg`, exactly as avatars are served.
 */
final class QuestionImageAction
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, string> extension to mime type */
    private const TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function __construct(private readonly MediaStorage $storage)
    {
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::AssessmentManage);
        $tenantId = $caller->tenantId
            ?? throw ValidationFailed::fromArray(['file' => 'No tenant in scope.']);

        $upload = $this->uploadedFile($request);
        $extension = strtolower(pathinfo((string) $upload->getClientFilename(), PATHINFO_EXTENSION));
        $type = strtolower((string) $upload->getClientMediaType());

        if (!array_key_exists($extension, self::TYPES) || !str_starts_with($type, 'image/')) {
            throw ValidationFailed::fromArray(['file' => 'A question image must be a JPEG, PNG, GIF or WebP.']);
        }

        $imageId = Uuid::uuid4()->toString();
        $key = sprintf('question-images/%s/%s.%s', $tenantId, $imageId, $extension);

        $stored = $this->storage->store($upload, $key);

        if ($stored['size'] > self::MAX_BYTES) {
            $this->storage->remove($key);

            throw ValidationFailed::fromArray(['file' => 'Keep a question image under 5 MB.']);
        }

        return Json::created($response, [
            'imageId'  => $imageId,
            'mediaUrl' => '/question-images/' . $imageId,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function stream(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::AssessmentManage);
        $imageId = $args['imageId'] ?? '';
        $tenantId = $caller->tenantId ?? throw new EntityNotFound('Question image', $imageId);

        foreach (self::TYPES as $extension => $mimeType) {
            $key = sprintf('question-images/%s/%s.%s', $tenantId, $imageId, $extension);

            if (!$this->storage->has($key)) {
                continue;
            }

            $stream = new Stream($this->storage->read($key));

            return $response
                ->withHeader('Content-Type', $mimeType)
                ->withHeader('Content-Length', (string) ($stream->getSize() ?? 0))
                ->withHeader('Cache-Control', 'private, max-age=86400')
                ->withBody($stream);
        }

        throw new EntityNotFound('Question image', $imageId);
    }

    private function uploadedFile(ServerRequestInterface $request): UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? $files['image'] ?? reset($files);

        if (!$file instanceof UploadedFileInterface) {
            throw ValidationFailed::fromArray(['file' => 'Attach an image.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => 'That file did not arrive in one piece.']);
        }

        return $file;
    }
}
