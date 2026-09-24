<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user message or a system line inside a conversation.
 * removed_at hides the content from members and keeps it for admin reveal.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|null $sender_id
 * @property int|null $reply_to_message_id
 * @property string $kind
 * @property string|null $body
 * @property \Illuminate\Support\Carbon|null $edited_at
 * @property \Illuminate\Support\Carbon|null $removed_at
 */
class ChatMessage extends Model
{
    public const KIND_USER = 'user';

    public const KIND_SYSTEM = 'system';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'reply_to_message_id',
        'kind',
        'body',
        'edited_at',
        'removed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'conversation_id' => 'integer',
        'sender_id' => 'integer',
        'reply_to_message_id' => 'integer',
        'edited_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(ChatMention::class, 'message_id');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function isSystem(): bool
    {
        return $this->kind === self::KIND_SYSTEM;
    }
}
