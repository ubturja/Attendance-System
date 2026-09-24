<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership in a conversation. left_at set means the person lost access.
 * The row is kept so a later rejoin restores the same membership.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon $joined_at
 * @property \Illuminate\Support\Carbon|null $left_at
 * @property int|null $last_read_message_id
 * @property int|null $last_delivered_message_id
 */
class ConversationMember extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'joined_at',
        'left_at',
        'last_read_message_id',
        'last_delivered_message_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'conversation_id' => 'integer',
        'user_id' => 'integer',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
        'last_read_message_id' => 'integer',
        'last_delivered_message_id' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }
}
