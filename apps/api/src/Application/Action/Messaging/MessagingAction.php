<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Messaging;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Messaging\Conversation;
use Central\Api\Domain\Messaging\ConversationKind;
use Central\Api\Domain\Messaging\DirectKey;
use Central\Api\Domain\Messaging\Message;
use Central\Api\Domain\Messaging\MessagingRepository;
use Central\Api\Domain\Messaging\Participant;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Messages between people in an organisation.
 *
 * GET    /v1/me/conversations
 * POST   /v1/me/conversations
 * GET    /v1/me/conversations/unread
 * GET    /v1/me/conversations/{id}
 * PATCH  /v1/me/conversations/{id}
 * POST   /v1/me/conversations/{id}/read
 * POST   /v1/me/conversations/{id}/participants
 * DELETE /v1/me/conversations/{id}/participants/{userId}
 * GET    /v1/me/conversations/{id}/messages
 * POST   /v1/me/conversations/{id}/messages
 * PUT    /v1/me/conversations/{id}/messages/{messageId}
 * DELETE /v1/me/conversations/{id}/messages/{messageId}
 *
 * Everything is under `/me`, and every route resolves the conversation through
 * the caller's own membership. There is no path here that takes a user id and
 * reads what they have been sent — which is what the legacy's endpoints did,
 * on the one feature where that means reading private messages.
 *
 * A conversation that is not the caller's answers 404 rather than 403: telling
 * somebody a conversation exists but is not theirs tells them who is talking.
 */
final class MessagingAction
{
    private const MAX_MESSAGE = 10_000;

    public function __construct(private readonly MessagingRepository $messaging)
    {
    }

    // --- conversations ----------------------------------------------------

    public function inbox(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);

        $listing  = $this->listing($request);
        $archived = filter_var($request->getQueryParams()['archived'] ?? false, FILTER_VALIDATE_BOOL);

        $result = $this->messaging->inbox($tenantId, $userId, $archived, $listing['page'], $listing['perPage']);

        return Json::page(
            $response,
            array_map(
                static fn (Conversation $c): array => $c->toArray($userId),
                $result['items'],
            ),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * One number, for the badge on the icon.
     */
    public function unread(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);

        return Json::ok($response, ['unread' => $this->messaging->unreadTotal($tenantId, $userId)]);
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

        return Json::ok($response, $this->require($tenantId, $userId, $args['id'])->toArray($userId));
    }

    /**
     * Starts a conversation, or finds the direct one that already exists.
     */
    public function start(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);

        $body = (array) ($request->getParsedBody() ?? []);
        $kind = ConversationKind::tryFrom(strtolower(trim((string) ($body['kind'] ?? 'direct'))));

        if ($kind === null) {
            throw ValidationFailed::fromArray([
                'kind' => 'Choose one of: ' . implode(', ', ConversationKind::values()) . '.',
            ]);
        }

        $participants = $this->userIds($body['participantIds'] ?? $body['userIds'] ?? []);

        if ($kind === ConversationKind::Direct) {
            return $this->startDirect($response, $tenantId, $userId, $participants);
        }

        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            throw ValidationFailed::fromArray(['name' => 'Give the conversation a name.']);
        }

        $conversation = $this->messaging->startGroup(
            $tenantId,
            $userId,
            $kind,
            mb_substr($name, 0, 255),
            $this->textOrNull($body['description'] ?? null),
            $participants,
        );

        return Json::created($response, $conversation->toArray($userId), '/v1/me/conversations/' . $conversation->id);
    }

    /**
     * Renaming, archiving, muting, pinning.
     *
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $conversation = $this->require($tenantId, $userId, $args['id']);
        $body         = (array) ($request->getParsedBody() ?? []);

        // Muting and pinning are the caller's own view of it, so anybody in
        // the conversation may set them for themselves.
        if (array_key_exists('isMuted', $body) || array_key_exists('isPinned', $body)) {
            $this->messaging->setViewState(
                $tenantId,
                $args['id'],
                $userId,
                array_key_exists('isMuted', $body) ? filter_var($body['isMuted'], FILTER_VALIDATE_BOOL) : null,
                array_key_exists('isPinned', $body) ? filter_var($body['isPinned'], FILTER_VALIDATE_BOOL) : null,
            );
        }

        if (array_key_exists('name', $body) || array_key_exists('description', $body)) {
            // The kind first: a direct conversation cannot be renamed by
            // anybody, so "you are not an admin" would be a misleading answer
            // to a request that no admin could satisfy either.
            if (!$conversation->kind->isNamed()) {
                // It is named by whoever else is in it.
                throw new ConflictingState(
                    'A direct message cannot be renamed.',
                    'conversation_is_direct',
                );
            }

            $this->mustAdminister($conversation, $userId);

            $name = trim((string) ($body['name'] ?? $conversation->name));

            if ($name === '') {
                throw ValidationFailed::fromArray(['name' => 'Give the conversation a name.']);
            }

            $this->messaging->rename(
                $tenantId,
                $args['id'],
                mb_substr($name, 0, 255),
                $this->textOrNull($body['description'] ?? $conversation->description),
            );
        }

        if (array_key_exists('isArchived', $body)) {
            $this->mustAdminister($conversation, $userId);
            $this->messaging->setArchived($tenantId, $args['id'], filter_var(
                $body['isArchived'],
                FILTER_VALIDATE_BOOL,
            ));
        }

        return Json::ok($response, $this->require($tenantId, $userId, $args['id'])->toArray($userId));
    }

    /**
     * @param array<string, string> $args
     */
    public function markRead(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $this->require($tenantId, $userId, $args['id']);
        $this->messaging->markRead($tenantId, $args['id'], $userId);

        return Json::ok($response, ['unread' => $this->messaging->unreadTotal($tenantId, $userId)]);
    }

    // --- participants -----------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function addParticipants(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $conversation = $this->require($tenantId, $userId, $args['id']);

        if (!$conversation->kind->isNamed()) {
            // Adding a third person to a two-person conversation would change
            // what everybody in it thought they were writing to. No admin can
            // do it either, so the kind is checked before the role.
            throw new ConflictingState(
                'A direct message is between two people. Start a group instead.',
                'conversation_is_direct',
            );
        }

        $this->mustAdminister($conversation, $userId);

        $body    = (array) ($request->getParsedBody() ?? []);
        $userIds = $this->userIds($body['userIds'] ?? []);

        if ($userIds === []) {
            throw ValidationFailed::fromArray(['userIds' => 'Say who is being added.']);
        }

        $added = $this->messaging->addParticipants($tenantId, $args['id'], $userIds, $userId);

        return Json::ok($response, [
            'added'        => $added,
            'conversation' => $this->require($tenantId, $userId, $args['id'])->toArray($userId),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function removeParticipant(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $conversation = $this->require($tenantId, $userId, $args['id']);
        $target       = $args['userId'];

        // Leaving needs no permission; removing somebody else does.
        if ($target !== $userId) {
            if (!$conversation->kind->isNamed()) {
                throw new ConflictingState(
                    'A direct message is between two people.',
                    'conversation_is_direct',
                );
            }

            $this->mustAdminister($conversation, $userId);
        }

        if (!$this->messaging->removeParticipant($tenantId, $args['id'], $target)) {
            throw new EntityNotFound('Participant', $target);
        }

        return Json::noContent($response);
    }

    // --- messages ---------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function messages(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $this->require($tenantId, $userId, $args['id']);

        $listing = $this->listing($request);
        $search  = $this->textOrNull($request->getQueryParams()['search'] ?? null);

        $result = $this->messaging->messages(
            $tenantId,
            $args['id'],
            $listing['page'],
            $listing['perPage'],
            $search,
        );

        return Json::page(
            $response,
            array_map(static fn (Message $m): array => $m->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function send(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $conversation = $this->require($tenantId, $userId, $args['id']);

        if ($conversation->isArchived()) {
            throw new ConflictingState('This conversation is archived.', 'conversation_archived');
        }

        $body    = (array) ($request->getParsedBody() ?? []);
        $content = $this->contentFrom($body);
        $replyTo = $this->uuidOrNull($body['replyToId'] ?? null);

        if ($replyTo !== null) {
            $original = $this->messaging->findMessage($tenantId, $replyTo);

            if ($original === null || $original->conversationId !== $args['id']) {
                // Replying to a message from another conversation would quote
                // it into a room its author never wrote to.
                throw ValidationFailed::fromArray(['replyToId' => 'That message is not in this conversation.']);
            }
        }

        $message = $this->messaging->send(new Message(
            id: null,
            tenantId: $tenantId,
            conversationId: $args['id'],
            content: $content,
            senderId: $userId,
            replyToId: $replyTo,
        ));

        // Sending is reading, so the sender's own message does not come back
        // as unread to them.
        $this->messaging->markRead($tenantId, $args['id'], $userId);

        return Json::created($response, $message->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $this->require($tenantId, $userId, $args['id']);

        $content = $this->contentFrom((array) ($request->getParsedBody() ?? []));

        $message = $this->messaging->edit($tenantId, $args['messageId'], $userId, $content);

        if ($message === null) {
            // Somebody else's, already deleted, or not there at all.
            throw new EntityNotFound('Message', $args['messageId']);
        }

        return Json::ok($response, $message->toArray());
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

        $conversation = $this->require($tenantId, $userId, $args['id']);
        $message      = $this->messaging->findMessage($tenantId, $args['messageId']);

        if ($message === null || $message->conversationId !== $args['id'] || $message->isDeleted()) {
            throw new EntityNotFound('Message', $args['messageId']);
        }

        // Your own, or anybody's if you started the conversation.
        if ($message->senderId !== $userId && !($this->me($conversation, $userId)?->moderates() ?? false)) {
            throw new AccessDenied('message.delete');
        }

        $this->messaging->deleteMessage($tenantId, $args['messageId'], $userId);

        return Json::noContent($response);
    }

    // --- plumbing ---------------------------------------------------------

    /**
     * @param list<string> $participants
     */
    private function startDirect(
        ResponseInterface $response,
        string $tenantId,
        string $userId,
        array $participants,
    ): ResponseInterface {
        if (count($participants) !== 1) {
            throw ValidationFailed::fromArray([
                'participantIds' => 'A direct message is between two people. Name one.',
            ]);
        }

        if (DirectKey::isSelf($userId, $participants[0])) {
            // A conversation with oneself is a notepad, and there is a notes
            // module for that.
            throw ValidationFailed::fromArray([
                'participantIds' => 'You cannot message yourself.',
            ]);
        }

        // Find-or-create, so two taps produce one conversation rather than a
        // second one that hides the first.
        $conversation = $this->messaging->directWith($tenantId, $userId, $participants[0]);

        return Json::ok($response, $conversation->toArray($userId));
    }

    private function require(string $tenantId, string $userId, string $id): Conversation
    {
        return $this->messaging->find($tenantId, $userId, $id)
            ?? throw new EntityNotFound('Conversation', $id);
    }

    private function me(Conversation $conversation, string $userId): ?Participant
    {
        foreach ($conversation->participants as $participant) {
            if ($participant->userId === $userId) {
                return $participant;
            }
        }

        return null;
    }

    private function mustAdminister(Conversation $conversation, string $userId): void
    {
        if (!($this->me($conversation, $userId)?->administers() ?? false)) {
            throw new AccessDenied('conversation.administer');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function contentFrom(array $body): string
    {
        $content = trim((string) ($body['content'] ?? ''));

        if ($content === '') {
            throw ValidationFailed::fromArray(['content' => 'Write something first.']);
        }

        if (mb_strlen($content) > self::MAX_MESSAGE) {
            throw ValidationFailed::fromArray([
                'content' => sprintf('A message is at most %d characters.', self::MAX_MESSAGE),
            ]);
        }

        return $content;
    }

    /**
     * @param  mixed        $value
     * @return list<string>
     */
    private function userIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            $id = $this->uuidOrNull($id);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{0: string, 1: string} tenant, user
     */
    private function caller(ServerRequestInterface $request): array
    {
        $caller = AuthenticationMiddleware::require($request);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::EnrollmentSelf->value);
        }

        return [$caller->tenantId, $caller->id];
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

    // --- reactions -----------------------------------------------------------

    /**
     * POST /v1/me/conversations/{id}/messages/{messageId}/reactions
     *
     * A toggle: reacting with an emoji already yours takes it back. One
     * gesture, one endpoint.
     *
     * @param array<string, string> $args
     */
    public function react(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        [$conversation] = $this->requireWithId($tenantId, $userId, $args['id'] ?? '');
        [, $messageId] = $this->requireMessageIn($tenantId, $conversation, $args['messageId'] ?? '');
        $emoji = trim((string) ($this->body($request)['emoji'] ?? ''));

        if ($emoji === '') {
            throw ValidationFailed::fromArray(['emoji' => 'Choose an emoji.']);
        }

        return Json::ok($response, [
            'messageId' => $messageId,
            'reactions' => $this->messaging->toggleReaction($tenantId, $messageId, $userId, $emoji),
        ]);
    }

    // --- drafts --------------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function saveDraft(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        [, $conversationId] = $this->requireWithId($tenantId, $userId, $args['id'] ?? '');
        $body = $this->body($request);
        $content = (string) ($body['content'] ?? '');

        // An emptied box is a deleted draft, not a draft of nothing — else
        // clearing what you typed leaves it waiting for you next time.
        if (trim($content) === '') {
            $this->messaging->deleteDraft($tenantId, $conversationId, $userId);

            return Json::noContent($response);
        }

        $this->messaging->saveDraft(
            $tenantId,
            $conversationId,
            $userId,
            $content,
            $this->textOrNull($body['replyToId'] ?? null),
        );

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function draft(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        [, $conversationId] = $this->requireWithId($tenantId, $userId, $args['id'] ?? '');

        return Json::ok($response, $this->messaging->draft($tenantId, $conversationId, $userId));
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteDraft(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        [, $conversationId] = $this->requireWithId($tenantId, $userId, $args['id'] ?? '');
        $this->messaging->deleteDraft($tenantId, $conversationId, $userId);

        return Json::noContent($response);
    }

    // --- pinned --------------------------------------------------------------

    /**
     * Pinning is for everybody in the conversation, so it takes the role that
     * governs the conversation rather than the one that governs a message.
     *
     * @param array<string, string> $args
     */
    public function pin(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        [$conversation] = $this->requireWithId($tenantId, $userId, $args['id'] ?? '');
        [, $messageId] = $this->requireMessageIn($tenantId, $conversation, $args['messageId'] ?? '');
        $pinned = (bool) ($this->body($request)['pinned'] ?? true);

        $updated = $this->messaging->setPinned($tenantId, $messageId, $pinned, $userId);

        if ($updated === null) {
            throw new ConflictingState('That message cannot be pinned.', 'message_not_pinnable');
        }

        return Json::ok($response, $updated->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function pinned(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        [, $conversationId] = $this->requireWithId($tenantId, $userId, $args['id'] ?? '');

        return Json::ok($response, array_map(
            static fn (Message $m): array => $m->toArray(),
            $this->messaging->pinnedIn($tenantId, $conversationId),
        ));
    }

    // --- saved ---------------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function save(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        [$conversation] = $this->requireWithId($tenantId, $userId, $args['id'] ?? '');
        [, $messageId] = $this->requireMessageIn($tenantId, $conversation, $args['messageId'] ?? '');

        $this->messaging->saveMessage(
            $tenantId,
            $messageId,
            $userId,
            $this->textOrNull($this->body($request)['note'] ?? null),
        );

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function unsave(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        [$conversation] = $this->requireWithId($tenantId, $userId, $args['id'] ?? '');
        [, $messageId] = $this->requireMessageIn($tenantId, $conversation, $args['messageId'] ?? '');

        $this->messaging->unsaveMessage($tenantId, $messageId, $userId);

        return Json::noContent($response);
    }

    /** GET /v1/me/messages/saved */
    public function saved(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);
        $listing = $this->listing($request);

        return Json::ok($response, $this->messaging->savedBy(
            $tenantId,
            $userId,
            $listing['perPage'],
            ($listing['page'] - 1) * $listing['perPage'],
        ));
    }

    // --- searching -----------------------------------------------------------

    /**
     * GET /v1/me/messages/search?q=…
     *
     * Only the conversations this person is in — see the repository.
     */
    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);

        $query = $request->getQueryParams();
        $phrase = trim((string) ($query['q'] ?? ''));

        if ($phrase === '') {
            throw ValidationFailed::fromArray(['q' => 'Type something to search for.']);
        }

        return Json::ok($response, array_map(
            static fn (Message $m): array => $m->toArray(),
            $this->messaging->search(
                $tenantId,
                $userId,
                $phrase,
                $this->textOrNull($query['conversationId'] ?? null),
                50,
            ),
        ));
    }

    // --- blocking ------------------------------------------------------------

    /** GET /v1/me/messages/blocked */
    public function blocked(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);

        return Json::ok($response, $this->messaging->blockedBy($tenantId, $userId));
    }

    /**
     * @param array<string, string> $args
     */
    public function block(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $blockedId = $args['userId'] ?? '';

        if ($blockedId === $userId) {
            throw ValidationFailed::fromArray(['userId' => 'You cannot block yourself.']);
        }

        $this->messaging->block(
            $tenantId,
            $userId,
            $blockedId,
            $this->textOrNull($this->body($request)['reason'] ?? null),
        );

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function unblock(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        $this->messaging->unblock($tenantId, $userId, $args['userId'] ?? '');

        return Json::noContent($response);
    }

    // --- preferences ---------------------------------------------------------

    public function preferences(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$tenantId, $userId] = $this->caller($request);

        return Json::ok($response, $this->messaging->preferences($tenantId, $userId));
    }

    public function savePreferences(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        [$tenantId, $userId] = $this->caller($request);

        return Json::ok($response, $this->messaging->savePreferences(
            $tenantId,
            $userId,
            $this->body($request),
        ));
    }

    // --- internals -----------------------------------------------------------

    /**
     * A message, checked against the conversation it is claimed to be in.
     *
     * Membership is settled by `require()` on the conversation; this stops a
     * member of one conversation reaching a message in another by sending its
     * id.
     */
    /**
     * A message, checked against the conversation it is claimed to be in,
     * and its id.
     *
     * The id comes back separately because `Message::$id` is nullable — an
     * unsaved message has none — and one that came from the repository
     * always has one. Returning it is how that is stated rather than
     * assumed.
     *
     * @return array{Message, string}
     */
    private function requireMessageIn(
        string $tenantId,
        Conversation $conversation,
        string $messageId,
    ): array {
        $message = $this->messaging->findMessage($tenantId, $messageId);

        if (
            $message === null
            || $message->id === null
            || $message->conversationId !== $conversation->id
        ) {
            // Membership is settled by `require()` on the conversation; this
            // stops a member of one conversation reaching a message in
            // another by sending its id.
            throw new EntityNotFound('Message', $messageId);
        }

        return [$message, $message->id];
    }

    /**
     * A conversation the caller is in, and its id.
     *
     * @return array{Conversation, string}
     */
    private function requireWithId(string $tenantId, string $userId, string $id): array
    {
        $conversation = $this->require($tenantId, $userId, $id);

        if ($conversation->id === null) {
            throw new EntityNotFound('Conversation', $id);
        }

        return [$conversation, $conversation->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
