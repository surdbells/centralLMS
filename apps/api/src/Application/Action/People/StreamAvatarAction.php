<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\People;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\People\PersonRepository;
use Central\Api\Infrastructure\Storage\MediaStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * Somebody's picture.
 *
 * GET /v1/users/{id}/avatar
 *
 * Served through the API rather than from a storage URL. Colleagues' faces are
 * personal data: a link that works for anybody who has it would leak the whole
 * staff directory of a tenant to anyone who guessed a bucket path.
 *
 * Any signed-in colleague may fetch one — a name and a face beside a message
 * or an attendance list is the ordinary case, and needing `user.view` for it
 * would leave every learner-facing screen with grey circles. The tenant check
 * is the one that matters, and it is the repository's: the person is looked up
 * within the caller's tenant, so an id from another organisation is a 404.
 */
final class StreamAvatarAction
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly MediaStorage $storage,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller   = AuthenticationMiddleware::require($request);
        $tenantId = $caller->tenantId ?? throw new EntityNotFound('Avatar', $args['id'] ?? '');
        $id       = $args['id'] ?? '';

        $person = $this->people->find($tenantId, $id) ?? throw new EntityNotFound('User', $id);

        // Exactly one avatar file exists per person — MyProfileAction removes
        // the others when it writes one — so the first hit is the answer.
        foreach (MyProfileAction::avatarExtensions() as $extension => $mimeType) {
            $key = MyProfileAction::avatarKey($tenantId, (string) $person->id, $extension);

            if (!$this->storage->has($key)) {
                continue;
            }

            $stream = new Stream($this->storage->read($key));

            return $response
                ->withHeader('Content-Type', $mimeType)
                ->withHeader('Content-Length', (string) ($stream->getSize() ?? 0))
                // Private, so a shared proxy cannot hand one tenant's staff
                // photo to another. The URL carries a checksum, so a new
                // picture is a new URL and this can be cached for a while.
                ->withHeader('Cache-Control', 'private, max-age=86400')
                ->withBody($stream);
        }

        // Saying so beats a zero-byte 200 that renders as a broken image.
        throw new EntityNotFound('Avatar', $id);
    }
}
