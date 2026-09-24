<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\MessagingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\AddMembersRequest;
use App\Http\Requests\Messaging\OpenDirectRequest;
use App\Http\Requests\Messaging\StoreConversationRequest;
use App\Http\Requests\Messaging\StoreMessageRequest;
use App\Http\Requests\Messaging\UpdateConversationRequest;
use App\Http\Requests\Messaging\UpdateMessageRequest;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessagingController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): array {
            return $this->messaging->listConversations(
                $this->user($request),
                $request->query('status') === 'archived',
            );
        }, 'Conversations retrieved successfully.');
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): array {
            return $this->messaging->showConversation($this->user($request), $conversation);
        }, 'Conversation retrieved successfully.');
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        return $this->run(function () use ($request): array {
            $conversation = $this->messaging->createGroup(
                $this->user($request),
                (string) $request->validated('kind'),
                (string) $request->validated('name'),
                $request->validated('send_permission'),
                $request->validated('member_ids') ?? [],
            );

            return $this->messaging->serializeConversation($this->user($request), $conversation);
        }, 'Group created successfully.', 201);
    }

    public function update(UpdateConversationRequest $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): array {
            $updated = $this->messaging->updateGroup(
                $this->user($request),
                $conversation,
                $request->safe()->only(['name', 'send_permission']),
            );

            return $this->messaging->serializeConversation($this->user($request), $updated);
        }, 'Group updated successfully.');
    }

    public function destroy(Request $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): null {
            $this->messaging->deleteGroup($this->user($request), $conversation);

            return null;
        }, 'Group archived successfully.');
    }

    public function restore(Request $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): array {
            $restored = $this->messaging->restoreGroup($this->user($request), $conversation);

            return $this->messaging->serializeConversation($this->user($request), $restored);
        }, 'Group restored successfully.');
    }

    public function join(Request $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): array {
            $joined = $this->messaging->joinGroup($this->user($request), $conversation);

            return $this->messaging->serializeConversation($this->user($request), $joined);
        }, 'You joined the group.');
    }

    public function addMembers(AddMembersRequest $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): array {
            $group = Conversation::query()->find($conversation);
            if ($group === null) {
                throw new MessagingException('That group could not be found.', 404);
            }

            $updated = $this->messaging->addMembers(
                $this->user($request),
                $group,
                $request->validated('user_ids'),
            );

            return $this->messaging->serializeConversation($this->user($request), $updated);
        }, 'People added to the group.');
    }

    public function removeMember(Request $request, int $conversation, int $user): JsonResponse
    {
        return $this->run(function () use ($request, $conversation, $user): array {
            $updated = $this->messaging->removeMember($this->user($request), $conversation, $user);

            return $this->messaging->serializeConversation($this->user($request), $updated);
        }, 'Person removed from the group.');
    }

    public function openDirect(OpenDirectRequest $request): JsonResponse
    {
        return $this->run(function () use ($request): array {
            $conversation = $this->messaging->openDirect(
                $this->user($request),
                (int) $request->validated('user_id'),
            );

            return $this->messaging->serializeConversation($this->user($request), $conversation);
        }, 'Direct conversation ready.');
    }

    public function messages(Request $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): array {
            $before = $request->query('before_id');
            $search = $request->query('q');

            return $this->messaging->messages(
                $this->user($request),
                $conversation,
                is_numeric($before) ? (int) $before : null,
                is_string($search) ? $search : null,
            );
        }, 'Messages retrieved successfully.');
    }

    public function send(StoreMessageRequest $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): array {
            $message = $this->messaging->sendMessage(
                $this->user($request),
                $conversation,
                $request->validated(),
                $this->files($request),
            );

            return $this->messaging->serializeMessage($this->user($request), $message);
        }, 'Message sent.', 201);
    }

    public function edit(UpdateMessageRequest $request, int $message): JsonResponse
    {
        return $this->run(function () use ($request, $message): array {
            $keep = null;
            if ($request->exists('keep_attachment_ids')) {
                $raw = $request->input('keep_attachment_ids');
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $raw = is_array($decoded) ? $decoded : [];
                }

                $keep = array_map(static fn (mixed $id): int => (int) $id, (array) $raw);
            }

            $updated = $this->messaging->editMessage(
                $this->user($request),
                $message,
                $request->validated(),
                $this->files($request),
                $keep,
            );

            return $this->messaging->serializeMessage($this->user($request), $updated);
        }, 'Message updated.');
    }

    public function deleteMessage(Request $request, int $message): JsonResponse
    {
        return $this->run(function () use ($request, $message): array {
            $deleted = $this->messaging->deleteMessage($this->user($request), $message);

            return $this->messaging->serializeMessage($this->user($request), $deleted);
        }, 'Message deleted.');
    }

    public function reveal(Request $request, int $message): JsonResponse
    {
        return $this->run(function () use ($request, $message): array {
            return $this->messaging->revealMessage($this->user($request), $message);
        }, 'Deleted message retrieved.');
    }

    public function read(Request $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): null {
            $messageId = $request->input('message_id');
            if (! is_numeric($messageId)) {
                throw new MessagingException('A message is required.');
            }

            $this->messaging->markRead($this->user($request), $conversation, (int) $messageId);

            return null;
        }, 'Conversation marked as read.');
    }

    public function delivered(Request $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): null {
            $messageId = $request->input('message_id');
            if (! is_numeric($messageId)) {
                throw new MessagingException('A message is required.');
            }

            $this->messaging->markDelivered($this->user($request), $conversation, (int) $messageId);

            return null;
        }, 'Messages marked as delivered.');
    }

    public function typing(Request $request, int $conversation): JsonResponse
    {
        return $this->run(function () use ($request, $conversation): null {
            $this->messaging->setTyping($this->user($request), $conversation);

            return null;
        }, 'Typing status updated.');
    }

    public function sync(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): array {
            $since = $request->query('since');
            $typing = $request->query('typing_conversation_id');

            return $this->messaging->sync(
                $this->user($request),
                is_string($since) ? $since : null,
                is_numeric($typing) ? (int) $typing : null,
            );
        }, 'Messaging sync completed.');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): array {
            return ['unread_count' => $this->messaging->unreadCount($this->user($request))];
        }, 'Unread count retrieved.');
    }

    public function directory(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): array {
            $conversationId = $request->query('conversation_id');

            return $this->messaging->directory(
                $this->user($request),
                is_numeric($conversationId) ? (int) $conversationId : null,
            );
        }, 'Directory retrieved successfully.');
    }

    public function groupOptions(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): array {
            $userId = $request->query('user_id');
            if (! is_numeric($userId)) {
                throw new MessagingException('A user is required.');
            }

            $subject = User::query()->find((int) $userId);
            if ($subject === null) {
                throw new MessagingException('That person could not be found.', 404);
            }

            $teamId = $request->query('team_id');

            return $this->messaging->groupOptionsFor(
                $this->user($request),
                $subject,
                is_numeric($teamId) ? (int) $teamId : null,
            );
        }, 'Group options retrieved successfully.');
    }

    public function ensureTeamGroup(Request $request, Team $team): JsonResponse
    {
        return $this->run(function () use ($request, $team): array {
            $ids = $request->input('add_user_ids', []);
            if (! is_array($ids)) {
                throw new MessagingException('Selected people must be a list.');
            }

            $conversation = $this->messaging->ensureTeamGroup(
                $this->user($request),
                $team,
                array_map(static fn (mixed $id): int => (int) $id, $ids),
            );

            return $this->messaging->serializeConversation($this->user($request), $conversation);
        }, 'Team group is ready.');
    }

    public function download(Request $request, int $attachment): JsonResponse|StreamedResponse
    {
        try {
            $file = $this->messaging->attachmentForDownload(
                $this->user($request),
                $attachment,
                $request->boolean('reveal'),
            );
        } catch (MessagingException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status);
        }

        $mime = $file->mime_type ?? 'application/octet-stream';
        $inline = str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';

        return Storage::disk($file->disk)->response(
            $file->path,
            $file->original_name,
            ['Content-Type' => $mime],
            $inline ? 'inline' : 'attachment',
        );
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function run(callable $callback, string $message, int $status = 200): JsonResponse
    {
        try {
            $data = $callback();
        } catch (MessagingException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->status);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new MessagingException('Unauthenticated.', 401);
        }

        return $user;
    }

    /**
     * @return list<UploadedFile>
     */
    private function files(Request $request): array
    {
        $files = $request->file('files', []);
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter(
            $files,
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
    }
}
