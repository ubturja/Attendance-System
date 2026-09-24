<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group chat (announcement, general, team) or a direct admin thread.
 *
 * Team chats are unique per team, including after a soft delete, so turning
 * the team group back on restores the same history.
 *
 * @property int $id
 * @property string $kind
 * @property int|null $team_id
 * @property string|null $name
 * @property string $send_permission
 * @property int|null $direct_user_low_id
 * @property int|null $direct_user_high_id
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Conversation extends Model
{
    use SoftDeletes;

    public const KIND_ANNOUNCEMENT = 'announcement';

    public const KIND_GENERAL = 'general';

    public const KIND_TEAM = 'team';

    public const KIND_DIRECT = 'direct';

    public const SEND_ADMINS_ONLY = 'admins_only';

    public const SEND_ALL_MEMBERS = 'all_members';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'kind',
        'team_id',
        'name',
        'send_permission',
        'direct_user_low_id',
        'direct_user_high_id',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'team_id' => 'integer',
        'direct_user_low_id' => 'integer',
        'direct_user_high_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class, 'conversation_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class, 'conversation_id')->latestOfMany();
    }

    public function isDirect(): bool
    {
        return $this->kind === self::KIND_DIRECT;
    }

    public function isTeam(): bool
    {
        return $this->kind === self::KIND_TEAM;
    }
}
