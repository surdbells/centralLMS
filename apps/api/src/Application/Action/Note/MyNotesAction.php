<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Note;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Note\Note;
use Central\Api\Domain\Note\NoteFilters;
use Central\Api\Domain\Note\NoteInput;
use Central\Api\Domain\Note\NoteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A learner's own notes.
 *
 * GET    /v1/me/notes
 * POST   /v1/me/notes
 * GET    /v1/me/notes/statistics
 * GET    /v1/me/notes/{id}
 * PUT    /v1/me/notes/{id}
 * PATCH  /v1/me/notes/{id}/pin
 * DELETE /v1/me/notes/{id}
 *
 * Seven routes for eleven legacy files, of which the eight that wrote or
 * searched could not run: `class/Note.php` inserts `title` and
 * `lesson_timestamp`, and the `notes` table has neither column. Every note in
 * the database was written by `course-player.php`, which has its own INSERT and
 * no way to edit, delete, pin or search.
 *
 * Under `/me` for the same reason the learner dashboard is: a note is always
 * the caller's. `user/notes/list.php` took `user_id` from the request body.
 */
final class MyNotesAction
{
    public function __construct(private readonly NoteRepository $notes)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);

        $listing = $this->listing($request);

        $result = $this->notes->paginate(
            $tenantId,
            $userId,
            NoteFilters::fromQuery($request->getQueryParams()),
            $listing['page'],
            $listing['perPage'],
        );

        return Json::page(
            $response,
            array_map(static fn (Note $n): array => $n->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    public function statistics(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);

        return Json::ok($response, $this->notes->statistics($tenantId, $userId));
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        return Json::ok($response, $this->require($tenantId, $userId, $args['id'])->toArray());
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);

        $violations = [];
        $input      = NoteInput::fromBody($this->body($request), $violations, requireLesson: true);

        if ($input === null) {
            throw ValidationFailed::fromArray($violations);
        }

        $lessonId = (string) $input->lessonId;
        $courseId = $this->notes->courseForReachableLesson($tenantId, $userId, $lessonId);

        if ($courseId === null) {
            // Covers a lesson that does not exist, one in another tenant, and
            // one on a course this person is not taking. Telling the three
            // apart would turn this into a catalogue enumerator.
            throw new EntityNotFound('Lesson', $lessonId);
        }

        $note = $this->notes->create(new Note(
            id: null,
            tenantId: $tenantId,
            userId: $userId,
            lessonId: $lessonId,
            courseId: $courseId,
            content: $input->content,
            title: $input->title,
            positionSeconds: $input->positionSeconds,
            isPinned: $input->isPinned ?? false,
        ));

        return Json::created($response, $note->toArray(), '/v1/me/notes/' . $note->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $violations = [];
        // A note does not move between lessons — see NoteRepository::update().
        $input = NoteInput::fromBody($this->body($request), $violations, requireLesson: false);

        if ($input === null) {
            throw ValidationFailed::fromArray($violations);
        }

        $note = $this->notes->update($tenantId, $userId, $args['id'], $input)
            ?? throw new EntityNotFound('Note', $args['id']);

        return Json::ok($response, $note->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function pin(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        // The new state, said out loud. `toggle_pin.php` ran
        // `SET is_pinned = NOT is_pinned`, so two taps racing each other leave
        // the pin wherever the second write landed and neither client knows.
        $body   = $this->body($request);
        $pinned = array_key_exists('isPinned', $body)
            ? filter_var($body['isPinned'], FILTER_VALIDATE_BOOL)
            : !$this->require($tenantId, $userId, $args['id'])->isPinned;

        $note = $this->notes->setPinned($tenantId, $userId, $args['id'], $pinned)
            ?? throw new EntityNotFound('Note', $args['id']);

        return Json::ok($response, $note->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        if (!$this->notes->softDelete($tenantId, $userId, $args['id'])) {
            throw new EntityNotFound('Note', $args['id']);
        }

        return Json::noContent($response);
    }

    // --- plumbing ---------------------------------------------------------

    /**
     * @return array{0: string, 1: string} tenant, user
     */
    private function caller(ServerRequestInterface $request): array
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::EnrollmentSelf);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::EnrollmentSelf->value);
        }

        return [$caller->tenantId, $caller->id];
    }

    private function require(string $tenantId, string $userId, string $id): Note
    {
        return $this->notes->find($tenantId, $userId, $id)
            ?? throw new EntityNotFound('Note', $id);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * @return array{page: int, perPage: int}
     */
    private function listing(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            'page'    => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, min(100, (int) ($query['perPage'] ?? 25))),
        ];
    }
}
