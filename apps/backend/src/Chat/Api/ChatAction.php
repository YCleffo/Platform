<?php

declare(strict_types=1);

namespace App\Chat\Api;

use App\Api\Shared\ResponseFactory;
use App\Chat\Application\ChatService;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Shared\Application\HttpError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Http\Status;
use Yiisoft\Router\CurrentRoute;

final readonly class ChatAction
{
    public function __invoke(
        ServerRequestInterface $request,
        CurrentRoute $route,
        ChatService $chat,
        ResponseFactory $responses,
    ): ResponseInterface {
        try {
            $identity = AuthenticatedIdentity::fromRequest($request);
            $operation = (string) $route->getArgument('operation');
            if ($operation === 'my_organizations') {
                return $responses->success($chat->myOrganizations($identity));
            }
            $id = $route->getArgument('id');
            $roomId = is_string($id) ? $id : '';
            $messageIdArg = $route->getArgument('messageId');
            $messageId = is_string($messageIdArg) ? $messageIdArg : '';

            $body = $request->getParsedBody();
            /** @var array<string, mixed> $body */
            $body = is_array($body) ? $body : [];
            /** @var array<string, mixed> $query */
            $query = $request->getQueryParams();

            $orgId = $request->getHeaderLine('X-Education-Organization');
            if ($orgId === '') {
                $orgId = (string) ($request->getHeaderLine('X-Platform-Organization') ?: ($body['organizationId'] ?? $query['organizationId'] ?? ''));
            }
            if ($orgId === '') {
                throw new HttpError(400, 'missing_organization_header');
            }

            $result = match ($operation) {
                'rooms' => $chat->listRooms($identity, $orgId, $query),
                'room' => $chat->getRoom($identity, $orgId, $roomId),
                'create_room' => $chat->createRoom($identity, $orgId, $body),
                'messages' => $chat->listMessages($identity, $orgId, $roomId, $query),
                'send_message' => $chat->sendMessage($identity, $orgId, $roomId, $body),
                'edit_message' => $chat->editMessage($identity, $orgId, $roomId, $messageId, (string) ($body['body'] ?? '')),
                'delete_message' => $chat->deleteMessage(
                    $identity,
                    $orgId,
                    $roomId,
                    $messageId,
                    (string) ($body['mode'] ?? $query['mode'] ?? 'for_me'),
                ),
                'react_message' => $chat->toggleReaction(
                    $identity,
                    $orgId,
                    $roomId,
                    (string) ($body['messageId'] ?? $messageId),
                    (string) ($body['emoji'] ?? ''),
                ),
                'read_room' => $chat->markRead(
                    $identity,
                    $orgId,
                    $roomId,
                    isset($body['lastSeenMessageId']) && is_string($body['lastSeenMessageId']) ? $body['lastSeenMessageId'] : null,
                ),
                'pin_message' => $chat->pinMessage(
                    $identity,
                    $orgId,
                    $roomId,
                    isset($body['messageId']) && is_string($body['messageId']) ? $body['messageId'] : ($messageId !== '' ? $messageId : null),
                ),
                'save_draft' => $chat->saveDraft($identity, $orgId, $roomId, $body),
                'room_preferences' => $chat->updateRoomPreferences($identity, $orgId, $roomId, $body),
                'search_messages' => $chat->searchMessages(
                    $identity,
                    $orgId,
                    (string) ($query['q'] ?? ''),
                    isset($query['roomId']) && is_string($query['roomId']) ? $query['roomId'] : null,
                ),
                'contacts' => $chat->contacts(
                    $identity,
                    $orgId,
                    isset($query['q']) && is_string($query['q']) ? $query['q'] : null,
                ),
                'add_member' => $chat->addMember(
                    $identity,
                    $orgId,
                    $roomId,
                    (string) ($body['personId'] ?? ''),
                ),
                'remove_member' => $chat->removeMember(
                    $identity,
                    $orgId,
                    $roomId,
                    (string) ($body['personId'] ?? $route->getArgument('personId') ?? ''),
                ),
                'leave_room' => $chat->leaveRoom($identity, $orgId, $roomId),
                default => throw new HttpError(404, 'not_found'),
            };

            $response = $responses->success($result);
            if ($request->getMethod() === 'POST' && in_array($operation, ['create_room', 'send_message'], true)) {
                return $response->withStatus(Status::CREATED);
            }
            return $response;
        } catch (HttpError $error) {
            return $responses->error($error->reason, $error->status);
        }
    }
}
