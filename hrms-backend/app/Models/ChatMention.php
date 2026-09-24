<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person mentioned on a message, or an @everyone flag for that message.
 *
 * @property int $id
 * @property int $message_id
 * @property int|null $user_id
 * @property bool $mentions_everyone
 */
class ChatMention extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'user_id',
        'mentions_everyone',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'message_id' => 'integer',
        'user_id' => 'integer',
        'mentions_everyone' => 'boolean',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
