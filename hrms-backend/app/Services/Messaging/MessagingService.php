<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Exceptions\MessagingException;
use App\Models\ChatAttachment;
use App\Models\ChatMention;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * HRMS messaging rules: groups, team chats, admin direct messages, files, and receipts.
 */
class MessagingService
{
    private const DELETED_PLACEHOLDER = 'This message was deleted';

    /**
     * @return list<array<string, mixed>>
     */
    public function listConversations(User $viewer, bool $archived): array
    {
        if ($archived) {
            $this->assertAdmin($viewer);

            $conversations = Conversation::onlyTrashed()
                ->where('kind', '!=', Conversation::KIND_DIRECT)
                ->with(['team', 'latestMessage.sender'])
                ->orderByDesc('deleted_at')
                ->get();

            return $conversations
                ->map(fn (Conversation $conversation): array => $this->presentConversation($conversation, $viewer))
                ->values()
                ->all();
        }

        $query = Conversation::query()->with(['team', 'latestMessage.sender', 'activeMembers.user']);

        if ($this->isAdmin($viewer)) {
            $query->where(function ($builder) use ($viewer): void {
                $builder->where('kind', '!=', Conversation::KIND_DIRECT)
                    ->orWhereHas('activeMembers', function ($members) use ($viewer): void {
                        $members->where('user_id', $viewer->id);
                    });
            });
        } else {
            $query->whereHas('activeMembers', function ($members) use ($viewer): void {
                $members->where('user_id', $viewer->id);
            });
        }

        return $query->get()
            ->sortByDesc(fn (Conversation $conversation): int => (int) ($conversation->latestMessage?->id ?? 0))
            ->values()
            ->map(fn (Conversation $conversation): array => $this->presentConversation($conversation, $viewer))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function showConversation(User $viewer, int $conversationId): array
    {
        $conversation = $this->findVisible($viewer, $conversationId);
        $conversation->load(['team', 'latestMessage.sender', 'activeMembers.user.team']);

        $payload = $this->presentConversation($conversation, $viewer);
        $payload['members'] = $conversation->activeMembers
            ->map(fn (ConversationMember $member): array => $this->presentMember($member))
            ->values()
            ->all();
        $payload['typing'] = $this->typingUsers($viewer, $conversation);

        return $payload;
    }

    public function createGroup(User $actor, string $kind, string $name, ?string $sendPermission, array $memberIds): Conversation
    {
        $this->assertAdmin($actor);

        if (! in_array($kind, [Conversation::KIND_ANNOUNCEMENT, Conversation::KIND_GENERAL], true)) {
            throw new MessagingException('Choose an announcement group or a general group.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new MessagingException('A group name is required.');
        }

        return DB::transaction(function () use ($actor, $kind, $name, $sendPermission, $memberIds): Conversation {
            $conversation = Conversation::query()->create([
                'kind' => $kind,
                'name' => $name,
                'send_permission' => $sendPermission ?? $this->defaultSendPermission($kind),
                'created_by' => $actor->id,
            ]);

            $this->systemLine($conversation, $actor, $actor->name.' created the group.');
            $this->addMembers($actor, $conversation, $memberIds);

            return $conversation->fresh(['team', 'activeMembers.user']) ?? $conversation;
        });
    }

    /**
     * @param  array{name?: string, send_permission?: string}  $changes
     */
    public function updateGroup(User $actor, int $conversationId, array $changes): Conversation
    {
        $this->assertAdmin($actor);
        $conversation = $this->findManageableGroup($actor, $conversationId);

        return DB::transaction(function () use ($actor, $conversation, $changes): Conversation {
            if (array_key_exists('name', $changes)) {
                if ($conversation->isTeam()) {
                    throw new MessagingException('A team group name always matches the team name.');
                }

                $name = trim((string) $changes['name']);
                if ($name === '') {
                    throw new MessagingException('A group name is required.');
                }

                if ($name !== $conversation->name) {
                    $conversation->name = $name;
                    $conversation->save();
                    $this->systemLine($conversation, $actor, $actor->name.' changed the group name to '.$name.'.');
                }
            }

            if (array_key_exists('send_permission', $changes)) {
                $permission = (string) $changes['send_permission'];
                $this->assertSendPermission($permission);

                if ($permission !== $conversation->send_permission) {
                    $conversation->send_permission = $permission;
                    $conversation->save();
                    $label = $permission === Conversation::SEND_ADMINS_ONLY ? 'admins only' : 'all members';
                    $this->systemLine($conversation, $actor, $actor->name.' changed who can send messages to '.$label.'.');
                }
            }

            return $conversation->fresh(['team', 'activeMembers.user']) ?? $conversation;
        });
    }

    public function deleteGroup(User $actor, int $conversationId): void
    {
        $this->assertAdmin($actor);
        $conversation = $this->findManageableGroup($actor, $conversationId);

        DB::transaction(function () use ($actor, $conversation): void {
            $this->systemLine($conversation, $actor, $actor->name.' archived the group.');
            $conversation->delete();
        });
    }

    public function restoreGroup(User $actor, int $conversationId): Conversation
    {
        $this->assertAdmin($actor);
        $conversation = Conversation::onlyTrashed()->find($conversationId);

        if ($conversation === null || $conversation->isDirect()) {
            throw new MessagingException('That group could not be found.', 404);
        }

        return DB::transaction(function () use ($actor, $conversation): Conversation {
            $conversation->restore();
            $this->systemLine($conversation, $actor, $actor->name.' restored the group.');

            return $conversation->fresh(['team', 'activeMembers.user']) ?? $conversation;
        });
    }

    public function joinGroup(User $actor, int $conversationId): Conversation
    {
        $this->assertAdmin($actor);
        $conversation = $this->findVisible($actor, $conversationId);

        if ($conversation->isDirect() || $conversation->trashed()) {
            throw new MessagingException('You can only join an active group.');
        }

        if ($this->activeMembership($actor, $conversation) !== null) {
            return $conversation;
        }

        return DB::transaction(function () use ($actor, $conversation): Conversation {
            $this->activateMember($conversation, $actor, markCaughtUp: true);
            $this->systemLine($conversation, $actor, $actor->name.' joined the group.');

            return $conversation->fresh(['team', 'activeMembers.user']) ?? $conversation;
        });
    }

    /**
     * @param  list<int>  $userIds
     */
    public function addMembers(User $actor, Conversation $conversation, array $userIds): Conversation
    {
        $this->assertAdmin($actor);

        if ($conversation->isDirect() || $conversation->trashed()) {
            throw new MessagingException('People can only be added to an active group.');
        }

        $ids = collect($userIds)->map(static fn (mixed $id): int => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return $conversation;
        }

        $users = User::query()->whereIn('id', $ids)->get()->keyBy('id');

        return DB::transaction(function () use ($actor, $conversation, $ids, $users): Conversation {
            foreach ($ids as $userId) {
                $user = $users->get($userId);
                if ($user === null) {
                    throw new MessagingException('One of the selected people could not be found.', 404);
                }

                $this->assertCanBeMember($user, $conversation);

                $membership = ConversationMember::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($membership !== null && $membership->isActive()) {
                    continue;
                }

                $this->activateMember($conversation, $user, markCaughtUp: true);
                $this->systemLine($conversation, $actor, $actor->name.' added '.$user->name.'.');
            }

            return $conversation->fresh(['team', 'activeMembers.user']) ?? $conversation;
        });
    }

    public function removeMember(User $actor, int $conversationId, int $userId): Conversation
    {
        $this->assertAdmin($actor);
        $conversation = $this->findManageableGroup($actor, $conversationId);
        $user = User::query()->find($userId);

        if ($user === null) {
            throw new MessagingException('That person could not be found.', 404);
        }

        $membership = $this->activeMembership($user, $conversation);
        if ($membership === null) {
            throw new MessagingException('That person is not in this group.');
        }

        DB::transaction(function () use ($actor, $conversation, $user, $membership): void {
            $membership->left_at = now();
            $membership->save();

            $line = (int) $actor->id === (int) $user->id
                ? $user->name.' left the group.'
                : $actor->name.' removed '.$user->name.'.';

            $this->systemLine($conversation, $actor, $line);
        });

        return $conversation->fresh(['team', 'activeMembers.user']) ?? $conversation;
    }

    public function openDirect(User $actor, int $otherUserId): Conversation
    {
        if ((int) $actor->id === $otherUserId) {
            throw new MessagingException('Choose someone else to message.');
        }

        $other = User::query()->find($otherUserId);
        if ($other === null || ! $other->is_active) {
            throw new MessagingException('That person is not available to message.', 404);
        }

        if (! $this->canDirectPair($actor, $other)) {
            throw new MessagingException('Employees can message admins only.', 403);
        }

        $low = min((int) $actor->id, (int) $other->id);
        $high = max((int) $actor->id, (int) $other->id);

        return DB::transaction(function () use ($actor, $other, $low, $high): Conversation {
            $conversation = Conversation::query()
                ->where('kind', Conversation::KIND_DIRECT)
                ->where('direct_user_low_id', $low)
                ->where('direct_user_high_id', $high)
                ->lockForUpdate()
                ->first();

            if ($conversation === null) {
                $conversation = Conversation::query()->create([
                    'kind' => Conversation::KIND_DIRECT,
                    'send_permission' => Conversation::SEND_ALL_MEMBERS,
                    'direct_user_low_id' => $low,
                    'direct_user_high_id' => $high,
                    'created_by' => $actor->id,
                    'name' => null,
                ]);
            }

            $this->activateMember($conversation, $actor, markCaughtUp: false);
            $this->activateMember($conversation, $other, markCaughtUp: false);

            return $conversation->fresh(['activeMembers.user']) ?? $conversation;
        });
    }

    /**
     * @return array{messages: list<array<string, mixed>>, has_more: bool}
     */
    public function messages(User $viewer, int $conversationId, ?int $beforeId, ?string $search): array
    {
        $conversation = $this->findVisible($viewer, $conversationId);
        $this->assertCanRead($viewer, $conversation);

        $query = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->with(['sender', 'replyTo.sender', 'attachments', 'mentions.user']);

        $term = trim((string) $search);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($builder) use ($like): void {
                $builder->where('body', 'like', $like)
                    ->orWhereHas('attachments', function ($attachments) use ($like): void {
                        $attachments->whereNull('removed_at')->where('original_name', 'like', $like);
                    });
            });

            if (! $this->isAdmin($viewer)) {
                $query->whereNull('removed_at');
            }

            $messages = $query->orderByDesc('id')->limit(50)->get()->reverse()->values();

            return [
                'messages' => $this->presentMessages($messages, $viewer, $conversation),
                'has_more' => false,
            ];
        }

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        $page = $query->orderByDesc('id')->limit(41)->get();
        $hasMore = $page->count() > 40;
        $messages = $page->take(40)->reverse()->values();

        return [
            'messages' => $this->presentMessages($messages, $viewer, $conversation),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<UploadedFile>  $files
     */
    public function sendMessage(User $sender, int $conversationId, array $payload, array $files): ChatMessage
    {
        $conversation = $this->findVisible($sender, $conversationId);
        $this->assertCanSend($sender, $conversation);

        $body = $this->nullableBody($payload['body'] ?? null);
        $this->assertHasContent($body, $files);
        $this->assertWithinLimit($this->incomingBytes($files));
        $mentionEveryone = $this->requestsEveryone($payload, $body);
        $this->assertEveryoneAllowed($sender, $conversation, $mentionEveryone);

        $mentionIds = $this->mentionIds($payload);
        $this->assertMentionedMembers($conversation, $mentionIds);

        $replyId = isset($payload['reply_to_message_id']) ? (int) $payload['reply_to_message_id'] : null;
        $this->assertReplyBelongs($conversation, $replyId);

        return DB::transaction(function () use ($sender, $conversation, $body, $files, $mentionEveryone, $mentionIds, $replyId): ChatMessage {
            $message = ChatMessage::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'reply_to_message_id' => $replyId,
                'kind' => ChatMessage::KIND_USER,
                'body' => $body,
            ]);

            $this->storeFiles($message, $files);
            $this->storeMentions($message, $mentionIds, $mentionEveryone);
            $conversation->touch();
            $this->advanceCursor($sender, $conversation, $message->id, read: true);

            return $message->load(['sender', 'replyTo.sender', 'attachments', 'mentions.user']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<UploadedFile>  $files
     * @param  list<int>|null  $keepAttachmentIds  null keeps every current file
     */
    public function editMessage(User $actor, int $messageId, array $payload, array $files, ?array $keepAttachmentIds): ChatMessage
    {
        $message = $this->findUserMessage($messageId);
        $conversation = $message->conversation ?? $message->conversation()->first();

        if ($conversation === null) {
            throw new MessagingException('That message could not be found.', 404);
        }

        $this->assertCanRead($actor, $conversation);

        if ((int) $message->sender_id !== (int) $actor->id) {
            throw new MessagingException('You can only edit your own messages.', 403);
        }

        if ($message->isRemoved() || $message->isSystem()) {
            throw new MessagingException('This message can no longer be edited.');
        }

        $body = $this->nullableBody($payload['body'] ?? null);
        $activeAttachments = $message->attachments()->whereNull('removed_at')->get();
        $keep = $keepAttachmentIds === null
            ? $activeAttachments->pluck('id')
            : collect($keepAttachmentIds)->map(static fn (mixed $id): int => (int) $id);

        $kept = $activeAttachments->filter(fn (ChatAttachment $attachment): bool => $keep->contains($attachment->id));
        $keptBytes = (int) $kept->sum('size_bytes');

        if ($body === null && $kept->isEmpty() && $files === []) {
            throw new MessagingException('A message needs text or a file.');
        }

        $this->assertWithinLimit($keptBytes + $this->incomingBytes($files));

        $mentionEveryone = $this->requestsEveryone($payload, $body);
        $this->assertEveryoneAllowed($actor, $conversation, $mentionEveryone);
        $mentionIds = $this->mentionIds($payload);
        $this->assertMentionedMembers($conversation, $mentionIds);

        return DB::transaction(function () use ($actor, $message, $conversation, $body, $files, $activeAttachments, $keep, $mentionEveryone, $mentionIds): ChatMessage {
            foreach ($activeAttachments as $attachment) {
                if (! $keep->contains($attachment->id)) {
                    $attachment->removed_at = now();
                    $attachment->save();
                }
            }

            $message->body = $body;
            $message->edited_at = now();
            $message->save();

            $this->storeFiles($message, $files);
            $message->mentions()->delete();
            $this->storeMentions($message, $mentionIds, $mentionEveryone);
            $conversation->touch();
            $this->advanceCursor($actor, $conversation, $message->id, read: true);

            return $message->fresh(['sender', 'replyTo.sender', 'attachments', 'mentions.user']) ?? $message;
        });
    }

    public function deleteMessage(User $actor, int $messageId): ChatMessage
    {
        $message = ChatMessage::query()->with('conversation')->find($messageId);
        if ($message === null || $message->conversation === null) {
            throw new MessagingException('That message could not be found.', 404);
        }

        $conversation = $message->conversation;
        $this->assertCanRead($actor, $conversation);

        $isSender = (int) $message->sender_id === (int) $actor->id;
        if (! $isSender && ! $this->isAdmin($actor)) {
            throw new MessagingException('You can only delete your own messages.', 403);
        }

        if ($message->isRemoved()) {
            throw new MessagingException('This message is already deleted.');
        }

        $message->removed_at = now();
        $message->save();
        $conversation->touch();

        return $message->fresh(['sender', 'replyTo.sender', 'attachments', 'mentions.user']) ?? $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function revealMessage(User $actor, int $messageId): array
    {
        $this->assertAdmin($actor);
        $message = ChatMessage::query()->with(['conversation', 'sender', 'replyTo.sender', 'attachments', 'mentions.user'])->find($messageId);

        if ($message === null || $message->conversation === null) {
            throw new MessagingException('That message could not be found.', 404);
        }

        $this->assertCanRead($actor, $message->conversation);

        if (! $message->isRemoved()) {
            throw new MessagingException('This message is still visible.');
        }

        return $this->presentMessage($message, $actor, $this->activeMembers($message->conversation), reveal: true);
    }

    public function markRead(User $user, int $conversationId, int $messageId): void
    {
        $conversation = $this->findVisible($user, $conversationId);
        $this->assertCanRead($user, $conversation);
        $this->assertMessageInConversation($conversation, $messageId);
        $this->advanceCursor($user, $conversation, $messageId, read: true);
    }

    public function markDelivered(User $user, int $conversationId, int $messageId): void
    {
        $conversation = $this->findVisible($user, $conversationId);
        $this->assertCanRead($user, $conversation);
        $this->assertMessageInConversation($conversation, $messageId);
        $this->advanceCursor($user, $conversation, $messageId, read: false);
    }

    public function setTyping(User $user, int $conversationId): void
    {
        $conversation = $this->findVisible($user, $conversationId);
        $this->assertCanSend($user, $conversation);

        $key = $this->typingCacheKey($conversation->id);
        $current = Cache::get($key, []);
        if (! is_array($current)) {
            $current = [];
        }

        $current[(string) $user->id] = [
            'id' => $user->id,
            'name' => $user->name,
            'until' => now()->addSeconds(4)->getTimestamp(),
        ];

        Cache::put($key, $current, now()->addSeconds(8));
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function typingUsers(User $viewer, Conversation $conversation): array
    {
        if (! $this->isActiveMember($viewer, $conversation)) {
            return [];
        }

        $current = Cache::get($this->typingCacheKey($conversation->id), []);
        if (! is_array($current)) {
            return [];
        }

        $now = now()->getTimestamp();
        $users = [];

        foreach ($current as $entry) {
            if (! is_array($entry) || (int) ($entry['until'] ?? 0) < $now) {
                continue;
            }

            if ((int) ($entry['id'] ?? 0) === (int) $viewer->id) {
                continue;
            }

            $users[] = [
                'id' => (int) $entry['id'],
                'name' => (string) ($entry['name'] ?? 'Someone'),
            ];
        }

        return $users;
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(User $viewer, ?string $since, ?int $typingConversationId): array
    {
        $sinceTime = null;
        if ($since !== null && trim($since) !== '') {
            try {
                $sinceTime = Carbon::parse($since);
            } catch (\Throwable) {
                throw new MessagingException('The sync time is invalid.');
            }
        }

        $messages = [];
        if ($sinceTime !== null) {
            $memberIds = ConversationMember::query()
                ->where('user_id', $viewer->id)
                ->whereNull('left_at')
                ->pluck('conversation_id');

            $changed = ChatMessage::query()
                ->whereIn('conversation_id', $memberIds)
                ->where('updated_at', '>=', $sinceTime)
                ->with(['sender', 'replyTo.sender', 'attachments', 'mentions.user', 'conversation'])
                ->orderBy('id')
                ->limit(200)
                ->get();

            foreach ($changed as $message) {
                if ($message->conversation === null) {
                    continue;
                }

                $messages[] = $this->presentMessage(
                    $message,
                    $viewer,
                    $this->activeMembers($message->conversation),
                );
            }
        }

        $typing = [];
        if ($typingConversationId !== null) {
            $conversation = Conversation::query()->find($typingConversationId);
            if ($conversation !== null && $this->isActiveMember($viewer, $conversation) && ! $conversation->trashed()) {
                $typing = $this->typingUsers($viewer, $conversation);
            }
        }

        return [
            'server_time' => now()->toIso8601String(),
            'conversations' => $this->listConversations($viewer, false),
            'messages' => $messages,
            'typing' => $typing,
            'unread_count' => $this->unreadCount($viewer),
        ];
    }

    public function unreadCount(User $viewer): int
    {
        return $this->unreadSummary($viewer)['unread_count'];
    }

    /**
     * @return array{unread_count: int, has_unread_mention: bool}
     */
    public function unreadSummary(User $viewer): array
    {
        $memberships = ConversationMember::query()
            ->where('user_id', $viewer->id)
            ->whereNull('left_at')
            ->whereHas('conversation', function ($query): void {
                $query->whereNull('deleted_at');
            })
            ->get();

        $total = 0;
        $mentioned = false;
        foreach ($memberships as $membership) {
            $total += $this->unreadCountFor($viewer, $membership);
            $mentioned = $mentioned || $this->hasUnreadMention($viewer, $membership);
        }

        return [
            'unread_count' => $total,
            'has_unread_mention' => $mentioned,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groupOptionsFor(User $actor, User $subject, ?int $previewTeamId): array
    {
        $this->assertAdmin($actor);

        $membershipIds = ConversationMember::query()
            ->where('user_id', $subject->id)
            ->whereNull('left_at')
            ->pluck('conversation_id');

        $groups = Conversation::query()
            ->whereNull('deleted_at')
            ->where('kind', '!=', Conversation::KIND_DIRECT)
            ->with('team')
            ->orderBy('name')
            ->get();

        return $groups
            ->filter(function (Conversation $group) use ($subject, $previewTeamId): bool {
                if (! $group->isTeam()) {
                    return true;
                }

                if ($subject->job_title === 'Admin') {
                    return true;
                }

                return $previewTeamId !== null && (int) $group->team_id === $previewTeamId;
            })
            ->map(function (Conversation $group) use ($membershipIds): array {
                return [
                    'id' => $group->id,
                    'name' => $this->displayName($group),
                    'kind' => $group->kind,
                    'is_member' => $membershipIds->contains($group->id),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $groupIds
     */
    public function syncGroupMemberships(User $actor, User $subject, array $groupIds): void
    {
        $this->assertAdmin($actor);

        $desired = collect($groupIds)->map(static fn (mixed $id): int => (int) $id)->unique()->values();
        $groups = Conversation::query()->whereIn('id', $desired)->get()->keyBy('id');

        foreach ($desired as $groupId) {
            $group = $groups->get($groupId);
            if ($group === null || $group->trashed() || $group->isDirect()) {
                throw new MessagingException('One of the selected groups is not available.');
            }

            $this->assertCanBeMember($subject, $group);
        }

        $current = ConversationMember::query()
            ->where('user_id', $subject->id)
            ->whereNull('left_at')
            ->whereHas('conversation', function ($query): void {
                $query->where('kind', '!=', Conversation::KIND_DIRECT)->whereNull('deleted_at');
            })
            ->get();

        DB::transaction(function () use ($actor, $subject, $desired, $groups, $current): void {
            foreach ($desired as $groupId) {
                if ($current->contains(fn (ConversationMember $member): bool => (int) $member->conversation_id === $groupId)) {
                    continue;
                }

                $group = $groups->get($groupId);
                if ($group instanceof Conversation) {
                    $this->addMembers($actor, $group, [$subject->id]);
                }
            }

            foreach ($current as $membership) {
                if ($desired->contains((int) $membership->conversation_id)) {
                    continue;
                }

                $this->removeMember($actor, (int) $membership->conversation_id, (int) $subject->id);
            }
        });
    }

    /**
     * @param  list<int>  $addUserIds
     */
    public function ensureTeamGroup(User $actor, Team $team, array $addUserIds = []): Conversation
    {
        $this->assertAdmin($actor);

        return DB::transaction(function () use ($actor, $team, $addUserIds): Conversation {
            $existing = Conversation::withTrashed()->where('team_id', $team->id)->first();

            if ($existing === null) {
                $existing = Conversation::query()->create([
                    'kind' => Conversation::KIND_TEAM,
                    'team_id' => $team->id,
                    'name' => $team->team_name,
                    'send_permission' => Conversation::SEND_ALL_MEMBERS,
                    'created_by' => $actor->id,
                ]);
                $this->systemLine($existing, $actor, $actor->name.' created the group.');
            } elseif ($existing->trashed()) {
                $existing->name = $team->team_name;
                $existing->restore();
                $existing->save();
                $this->systemLine($existing, $actor, $actor->name.' restored the group.');
            } elseif ($existing->name !== $team->team_name) {
                $existing->name = $team->team_name;
                $existing->save();
            }

            if ($addUserIds !== []) {
                $this->addMembers($actor, $existing, $addUserIds);
            }

            return $existing->fresh(['team', 'activeMembers.user']) ?? $existing;
        });
    }

    public function renameTeamGroup(User $actor, Team $team): void
    {
        $conversation = Conversation::withTrashed()->where('team_id', $team->id)->first();
        if ($conversation === null || $conversation->name === $team->team_name) {
            return;
        }

        $conversation->name = $team->team_name;
        $conversation->save();

        if (! $conversation->trashed()) {
            $this->systemLine($conversation, $actor, $actor->name.' changed the group name to '.$team->team_name.'.');
        }
    }

    public function archiveTeamGroup(User $actor, Team $team): void
    {
        $conversation = Conversation::query()->where('team_id', $team->id)->first();
        if ($conversation === null) {
            return;
        }

        $this->systemLine($conversation, $actor, $actor->name.' archived the group.');
        $conversation->delete();
    }

    public function restoreTeamGroup(User $actor, Team $team): void
    {
        $conversation = Conversation::onlyTrashed()->where('team_id', $team->id)->first();
        if ($conversation === null) {
            return;
        }

        $conversation->restore();
        $conversation->name = $team->team_name;
        $conversation->save();
        $this->systemLine($conversation, $actor, $actor->name.' restored the group.');
    }

    public function removeUserFromTeamChat(User $user, int $teamId): void
    {
        // Include an archived team chat so a later restore cannot put this person back in.
        $conversation = Conversation::withTrashed()
            ->where('team_id', $teamId)
            ->where('kind', Conversation::KIND_TEAM)
            ->first();

        if ($conversation === null) {
            return;
        }

        $membership = $this->activeMembership($user, $conversation);
        if ($membership === null) {
            return;
        }

        $membership->left_at = now();
        $membership->save();
        $this->systemLine($conversation, $user, $user->name.' left the group.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function directory(User $viewer, ?int $conversationId): array
    {
        if ($conversationId !== null) {
            $this->assertAdmin($viewer);
            $conversation = $this->findManageableGroup($viewer, $conversationId);
            $existing = $conversation->activeMembers()->pluck('user_id');

            return User::query()
                ->where('is_active', true)
                ->whereNotIn('id', $existing)
                ->with('team')
                ->orderBy('name')
                ->get()
                ->filter(fn (User $user): bool => $this->canBeMember($user, $conversation))
                ->map(fn (User $user): array => $this->presentDirectoryUser($user))
                ->values()
                ->all();
        }

        $query = User::query()->where('is_active', true)->where('id', '!=', $viewer->id)->with('team')->orderBy('name');

        if (! $this->isAdmin($viewer)) {
            $query->where('job_title', 'Admin');
        }

        return $query->get()->map(fn (User $user): array => $this->presentDirectoryUser($user))->all();
    }

    public function attachmentForDownload(User $viewer, int $attachmentId, bool $reveal): ChatAttachment
    {
        $attachment = ChatAttachment::query()->with('message.conversation')->find($attachmentId);
        if ($attachment === null || $attachment->message === null || $attachment->message->conversation === null) {
            throw new MessagingException('That file could not be found.', 404);
        }

        $this->assertCanRead($viewer, $attachment->message->conversation);

        if ($attachment->removed_at !== null) {
            throw new MessagingException('That file could not be found.', 404);
        }

        if ($attachment->message->isRemoved() && (! $reveal || ! $this->isAdmin($viewer))) {
            throw new MessagingException('That file could not be found.', 404);
        }

        return $attachment;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeConversation(User $viewer, Conversation $conversation): array
    {
        $conversation->loadMissing(['team', 'latestMessage.sender', 'activeMembers.user']);

        return $this->presentConversation($conversation, $viewer);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMessage(User $viewer, ChatMessage $message): array
    {
        $message->loadMissing(['sender', 'replyTo.sender', 'attachments', 'mentions.user', 'conversation']);
        $conversation = $message->conversation;

        if ($conversation === null) {
            throw new MessagingException('That message could not be found.', 404);
        }

        $message->setRelation('conversation', $conversation);

        return $this->presentMessage($message, $viewer, $this->activeMembers($conversation));
    }

    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function presentMessages(Collection $messages, User $viewer, Conversation $conversation): array
    {
        $members = $this->activeMembers($conversation);

        return $messages
            ->map(function (ChatMessage $message) use ($viewer, $members, $conversation): array {
                $message->setRelation('conversation', $conversation);

                return $this->presentMessage($message, $viewer, $members);
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ConversationMember>  $activeMembers
     * @return array<string, mixed>
     */
    private function presentMessage(ChatMessage $message, User $viewer, Collection $activeMembers, bool $reveal = false): array
    {
        $showBody = ! $message->isRemoved() || $reveal;
        $reply = $message->replyTo;

        $seenBy = [];
        $receipt = null;
        if (! $message->isSystem() && (int) $message->sender_id === (int) $viewer->id) {
            $seenBy = $activeMembers
                ->filter(function (ConversationMember $member) use ($message, $viewer): bool {
                    return (int) $member->user_id !== (int) $viewer->id
                        && $member->last_read_message_id !== null
                        && (int) $member->last_read_message_id >= (int) $message->id;
                })
                ->map(fn (ConversationMember $member): array => [
                    'id' => $member->user_id,
                    'name' => $member->user?->name ?? 'Member',
                ])
                ->values()
                ->all();

            $others = $activeMembers->filter(fn (ConversationMember $member): bool => (int) $member->user_id !== (int) $viewer->id)->count();
            $conversation = $message->conversation;
            if ($conversation !== null && $conversation->isDirect()) {
                $other = $activeMembers->first(fn (ConversationMember $member): bool => (int) $member->user_id !== (int) $viewer->id);
                if ($other !== null && $other->last_read_message_id !== null && (int) $other->last_read_message_id >= (int) $message->id) {
                    $receipt = 'seen';
                } elseif ($other !== null && $other->last_delivered_message_id !== null && (int) $other->last_delivered_message_id >= (int) $message->id) {
                    $receipt = 'delivered';
                } else {
                    $receipt = 'sent';
                }
            } elseif ($others > 0 && count($seenBy) >= $others) {
                $receipt = 'seen';
            } else {
                $receipt = 'sent';
            }
        }

        $attachments = [];
        if ($showBody) {
            $attachments = $message->attachments
                ->filter(fn (ChatAttachment $attachment): bool => $attachment->removed_at === null)
                ->map(fn (ChatAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                ])
                ->values()
                ->all();
        }

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'kind' => $message->kind,
            'body' => $showBody ? $message->body : null,
            'placeholder' => $message->isRemoved() && ! $reveal ? self::DELETED_PLACEHOLDER : null,
            'removed' => $message->isRemoved(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'sender' => $message->sender === null ? null : [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'job_title' => $message->sender->job_title,
            ],
            'reply_to' => $reply === null ? null : [
                'id' => $reply->id,
                'body' => $reply->isRemoved() ? null : $reply->body,
                'placeholder' => $reply->isRemoved() ? self::DELETED_PLACEHOLDER : null,
                'removed' => $reply->isRemoved(),
                'sender_name' => $reply->sender?->name,
            ],
            'attachments' => $attachments,
            'mention_user_ids' => $message->mentions
                ->filter(fn (ChatMention $mention): bool => $mention->user_id !== null)
                ->map(fn (ChatMention $mention): int => (int) $mention->user_id)
                ->values()
                ->all(),
            'mention_everyone' => $message->mentions->contains(fn (ChatMention $mention): bool => $mention->mentions_everyone),
            'receipt' => $receipt,
            'seen_by' => $seenBy,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConversation(Conversation $conversation, User $viewer): array
    {
        $membership = ConversationMember::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $viewer->id)
            ->whereNull('left_at')
            ->first();

        $latest = $conversation->latestMessage;
        $other = null;
        if ($conversation->isDirect()) {
            $otherId = (int) $conversation->direct_user_low_id === (int) $viewer->id
                ? (int) $conversation->direct_user_high_id
                : (int) $conversation->direct_user_low_id;
            $otherUser = User::query()->find($otherId);
            if ($otherUser !== null) {
                $other = [
                    'id' => $otherUser->id,
                    'name' => $otherUser->name,
                    'job_title' => $otherUser->job_title,
                ];
            }
        }

        $isMember = $membership !== null;

        return [
            'id' => $conversation->id,
            'kind' => $conversation->kind,
            'name' => $conversation->isDirect() ? ($other['name'] ?? 'Direct message') : $this->displayName($conversation),
            'send_permission' => $conversation->send_permission,
            'team_id' => $conversation->team_id,
            'is_member' => $isMember,
            'deleted_at' => $conversation->deleted_at?->toIso8601String(),
            'unread_count' => $membership === null ? 0 : $this->unreadCountFor($viewer, $membership),
            'has_unread_mention' => $membership !== null && $this->hasUnreadMention($viewer, $membership),
            'members_count' => $conversation->relationLoaded('activeMembers')
                ? $conversation->activeMembers->count()
                : $conversation->activeMembers()->count(),
            'other_user' => $other,
            'last_message' => $latest === null ? null : [
                'id' => $latest->id,
                'body' => $latest->isRemoved() ? null : $latest->body,
                'placeholder' => $latest->isRemoved() ? self::DELETED_PLACEHOLDER : null,
                'kind' => $latest->kind,
                'sender_id' => $latest->sender_id,
                'sender_name' => $latest->sender?->name,
                'created_at' => $latest->created_at?->toIso8601String(),
            ],
            'permissions' => [
                'can_send' => $isMember && ! $conversation->trashed() && $this->canSend($viewer, $conversation),
                'can_join' => $this->isAdmin($viewer) && ! $conversation->isDirect() && ! $isMember && ! $conversation->trashed(),
                'can_manage_members' => $this->isAdmin($viewer) && ! $conversation->isDirect() && ! $conversation->trashed(),
                'can_rename' => $this->isAdmin($viewer) && ! $conversation->isTeam() && ! $conversation->isDirect() && ! $conversation->trashed(),
                'can_change_send_permission' => $this->isAdmin($viewer) && ! $conversation->isDirect() && ! $conversation->trashed(),
                'can_delete' => $this->isAdmin($viewer) && ! $conversation->isDirect() && ! $conversation->trashed(),
                'can_restore' => $this->isAdmin($viewer) && ! $conversation->isDirect() && $conversation->trashed(),
                'can_mention_everyone' => $isMember && $this->canMentionEveryone($viewer, $conversation),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMember(ConversationMember $member): array
    {
        return [
            'id' => $member->user_id,
            'name' => $member->user?->name ?? 'Member',
            'job_title' => $member->user?->job_title,
            'team_id' => $member->user?->team_id,
            'team_name' => $member->user?->team?->team_name,
            'last_read_message_id' => $member->last_read_message_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDirectoryUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'job_title' => $user->job_title,
            'team_id' => $user->team_id,
            'team_name' => $user->team?->team_name,
        ];
    }

    private function displayName(Conversation $conversation): string
    {
        if ($conversation->isTeam()) {
            return $conversation->team?->team_name ?? $conversation->name ?? 'Team group';
        }

        return $conversation->name ?? 'Group';
    }

    private function unreadCountFor(User $viewer, ConversationMember $membership): int
    {
        $query = ChatMessage::query()
            ->where('conversation_id', $membership->conversation_id)
            ->where(function ($builder) use ($viewer): void {
                $builder->whereNull('sender_id')->orWhere('sender_id', '!=', $viewer->id);
            });

        if ($membership->last_read_message_id !== null) {
            $query->where('id', '>', $membership->last_read_message_id);
        }

        return $query->count();
    }

    private function hasUnreadMention(User $viewer, ConversationMember $membership): bool
    {
        $query = ChatMessage::query()
            ->where('conversation_id', $membership->conversation_id)
            ->where(function ($builder) use ($viewer): void {
                $builder->whereNull('sender_id')->orWhere('sender_id', '!=', $viewer->id);
            })
            ->whereHas('mentions', function ($mentions) use ($viewer): void {
                $mentions->where('mentions_everyone', true)->orWhere('user_id', $viewer->id);
            });

        if ($membership->last_read_message_id !== null) {
            $query->where('id', '>', $membership->last_read_message_id);
        }

        return $query->exists();
    }

    private function findVisible(User $viewer, int $conversationId): Conversation
    {
        $conversation = Conversation::withTrashed()->find($conversationId);
        if ($conversation === null) {
            throw new MessagingException('That conversation could not be found.', 404);
        }

        if ($conversation->trashed()) {
            if (! $this->isAdmin($viewer) || $conversation->isDirect()) {
                throw new MessagingException('That conversation could not be found.', 404);
            }

            return $conversation;
        }

        if ($conversation->isDirect()) {
            if (! $this->isActiveMember($viewer, $conversation)) {
                throw new MessagingException('You do not have access to this conversation.', 403);
            }

            return $conversation;
        }

        if ($this->isAdmin($viewer) || $this->isActiveMember($viewer, $conversation)) {
            return $conversation;
        }

        throw new MessagingException('You do not have access to this conversation.', 403);
    }

    private function findManageableGroup(User $actor, int $conversationId): Conversation
    {
        $this->assertAdmin($actor);
        $conversation = Conversation::query()->find($conversationId);

        if ($conversation === null || $conversation->isDirect()) {
            throw new MessagingException('That group could not be found.', 404);
        }

        return $conversation;
    }

    private function assertCanRead(User $viewer, Conversation $conversation): void
    {
        if ($conversation->trashed()) {
            throw new MessagingException('Restore this group to view its messages.', 422);
        }

        if (! $this->isActiveMember($viewer, $conversation)) {
            throw new MessagingException('Join this group to read its messages.', 403);
        }
    }

    private function assertCanSend(User $user, Conversation $conversation): void
    {
        $this->assertCanRead($user, $conversation);

        if (! $this->canSend($user, $conversation)) {
            throw new MessagingException('You cannot send messages in this conversation.', 403);
        }
    }

    private function canSend(User $user, Conversation $conversation): bool
    {
        if ($conversation->trashed() || ! $this->isActiveMember($user, $conversation)) {
            return false;
        }

        if ($conversation->isDirect()) {
            $other = $this->otherDirectUser($conversation, $user);
            if ($other === null || ! $other->is_active) {
                return false;
            }

            return $this->canDirectPair($user, $other);
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        if ($conversation->send_permission !== Conversation::SEND_ALL_MEMBERS) {
            return false;
        }

        if ($conversation->isTeam()) {
            return $user->team_id !== null && (int) $user->team_id === (int) $conversation->team_id;
        }

        return true;
    }

    private function canMentionEveryone(User $user, Conversation $conversation): bool
    {
        if ($conversation->isDirect() || ! $this->canSend($user, $conversation)) {
            return false;
        }

        if ($conversation->kind === Conversation::KIND_ANNOUNCEMENT) {
            return $this->isAdmin($user);
        }

        if ($conversation->kind === Conversation::KIND_GENERAL) {
            return true;
        }

        if ($conversation->isTeam()) {
            if ($this->isAdmin($user)) {
                return true;
            }

            return $user->team_id !== null && (int) $user->team_id === (int) $conversation->team_id;
        }

        return false;
    }

    private function canDirectPair(User $first, User $second): bool
    {
        if ((int) $first->id === (int) $second->id) {
            return false;
        }

        return $this->isAdmin($first) || $this->isAdmin($second);
    }

    private function canBeMember(User $user, Conversation $conversation): bool
    {
        if (! $user->is_active || $conversation->isDirect() || $conversation->trashed()) {
            return false;
        }

        if (! $conversation->isTeam()) {
            return true;
        }

        if ($this->isAdmin($user)) {
            return true;
        }

        return $user->team_id !== null && (int) $user->team_id === (int) $conversation->team_id;
    }

    private function assertCanBeMember(User $user, Conversation $conversation): void
    {
        if ($this->canBeMember($user, $conversation)) {
            return;
        }

        if ($conversation->isTeam()) {
            throw new MessagingException($user->name.' can only be added to the '.$this->displayName($conversation).' group when that is their team.');
        }

        throw new MessagingException($user->name.' cannot be added to this group.');
    }

    private function otherDirectUser(Conversation $conversation, User $viewer): ?User
    {
        $otherId = (int) $conversation->direct_user_low_id === (int) $viewer->id
            ? (int) $conversation->direct_user_high_id
            : (int) $conversation->direct_user_low_id;

        return User::query()->find($otherId);
    }

    private function activateMember(Conversation $conversation, User $user, bool $markCaughtUp): void
    {
        $latestId = $markCaughtUp ? (int) $conversation->messages()->max('id') : 0;
        $membership = ConversationMember::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            ConversationMember::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'joined_at' => now(),
                'left_at' => null,
                'last_read_message_id' => $latestId > 0 ? $latestId : null,
                'last_delivered_message_id' => $latestId > 0 ? $latestId : null,
            ]);

            return;
        }

        $membership->joined_at = now();
        $membership->left_at = null;
        if ($markCaughtUp && $latestId > 0) {
            $membership->last_read_message_id = max((int) $membership->last_read_message_id, $latestId);
            $membership->last_delivered_message_id = max((int) $membership->last_delivered_message_id, $latestId);
        }
        $membership->save();
    }

    private function systemLine(Conversation $conversation, User $actor, string $body): void
    {
        $message = ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $actor->id,
            'kind' => ChatMessage::KIND_SYSTEM,
            'body' => $body,
        ]);

        $conversation->touch();
        $this->advanceCursor($actor, $conversation, $message->id, read: true);
    }

    private function advanceCursor(User $user, Conversation $conversation, int $messageId, bool $read): void
    {
        $membership = $this->activeMembership($user, $conversation);
        if ($membership === null) {
            return;
        }

        $membership->last_delivered_message_id = max((int) $membership->last_delivered_message_id, $messageId);
        if ($read) {
            $membership->last_read_message_id = max((int) $membership->last_read_message_id, $messageId);
        }
        $membership->save();
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function storeFiles(ChatMessage $message, array $files): void
    {
        foreach ($files as $file) {
            $stored = $file->store('chat/'.$message->conversation_id, 'local');
            if ($stored === false) {
                throw new MessagingException('A file could not be stored.', 500);
            }

            ChatAttachment::query()->create([
                'message_id' => $message->id,
                'disk' => 'local',
                'path' => $stored,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => (int) $file->getSize(),
            ]);
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function storeMentions(ChatMessage $message, array $userIds, bool $everyone): void
    {
        if ($everyone) {
            ChatMention::query()->create([
                'message_id' => $message->id,
                'user_id' => null,
                'mentions_everyone' => true,
            ]);
        }

        foreach ($userIds as $userId) {
            ChatMention::query()->create([
                'message_id' => $message->id,
                'user_id' => $userId,
                'mentions_everyone' => false,
            ]);
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function incomingBytes(array $files): int
    {
        $total = 0;
        foreach ($files as $file) {
            $total += (int) $file->getSize();
        }

        return $total;
    }

    private function assertWithinLimit(int $bytes): void
    {
        $limit = (int) config('messaging.max_message_bytes');
        if ($bytes > $limit) {
            throw new MessagingException('One message cannot be larger than 1GB.');
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function assertHasContent(?string $body, array $files): void
    {
        if ($body === null && $files === []) {
            throw new MessagingException('A message needs text or a file.');
        }
    }

    private function nullableBody(mixed $body): ?string
    {
        if (! is_string($body)) {
            return null;
        }

        $trimmed = trim($body);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requestsEveryone(array $payload, ?string $body): bool
    {
        $flag = filter_var($payload['mention_everyone'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $flag || ($body !== null && preg_match('/(^|\s)@everyone\b/i', $body) === 1);
    }

    private function assertEveryoneAllowed(User $user, Conversation $conversation, bool $mentionEveryone): void
    {
        if (! $mentionEveryone) {
            return;
        }

        if (! $this->canMentionEveryone($user, $conversation)) {
            throw new MessagingException('You cannot mention everyone in this group.', 403);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function mentionIds(array $payload): array
    {
        $ids = $payload['mention_user_ids'] ?? [];
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assertMentionedMembers(Conversation $conversation, array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $activeIds = $conversation->activeMembers()->pluck('user_id')->map(static fn (mixed $id): int => (int) $id);

        foreach ($userIds as $userId) {
            if (! $activeIds->contains($userId)) {
                throw new MessagingException('Mentions can only include people who are in this conversation.');
            }
        }
    }

    private function assertReplyBelongs(Conversation $conversation, ?int $replyId): void
    {
        if ($replyId === null || $replyId === 0) {
            return;
        }

        $exists = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', $replyId)
            ->exists();

        if (! $exists) {
            throw new MessagingException('The message you are replying to is not in this conversation.');
        }
    }

    private function assertMessageInConversation(Conversation $conversation, int $messageId): void
    {
        $exists = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', $messageId)
            ->exists();

        if (! $exists) {
            throw new MessagingException('That message is not in this conversation.', 404);
        }
    }

    private function findUserMessage(int $messageId): ChatMessage
    {
        $message = ChatMessage::query()->with(['conversation', 'attachments'])->find($messageId);
        if ($message === null) {
            throw new MessagingException('That message could not be found.', 404);
        }

        return $message;
    }

    /**
     * @return Collection<int, ConversationMember>
     */
    private function activeMembers(Conversation $conversation): Collection
    {
        return $conversation->activeMembers()->with('user')->get();
    }

    private function activeMembership(User $user, Conversation $conversation): ?ConversationMember
    {
        return ConversationMember::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();
    }

    private function isActiveMember(User $user, Conversation $conversation): bool
    {
        return $this->activeMembership($user, $conversation) !== null;
    }

    private function isAdmin(User $user): bool
    {
        return $user->job_title === 'Admin';
    }

    private function assertAdmin(User $user): void
    {
        if (! $this->isAdmin($user)) {
            throw new MessagingException('Only an admin can do that.', 403);
        }
    }

    private function assertSendPermission(string $permission): void
    {
        if (! in_array($permission, [Conversation::SEND_ADMINS_ONLY, Conversation::SEND_ALL_MEMBERS], true)) {
            throw new MessagingException('Choose admins only or all members.');
        }
    }

    private function defaultSendPermission(string $kind): string
    {
        return $kind === Conversation::KIND_ANNOUNCEMENT
            ? Conversation::SEND_ADMINS_ONLY
            : Conversation::SEND_ALL_MEMBERS;
    }

    private function typingCacheKey(int $conversationId): string
    {
        return 'messaging:typing:'.$conversationId;
    }
}
