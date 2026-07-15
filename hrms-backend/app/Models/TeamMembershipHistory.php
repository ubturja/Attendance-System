<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit Entity: team_membership_histories
 *
 * Tracks when a user joined or left a team. Open membership periods have
 * left_at = NULL until the user is reassigned or removed from the team.
 *
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property \Illuminate\Support\Carbon|null $joined_at
 * @property \Illuminate\Support\Carbon|null $left_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TeamMembershipHistory extends Model
{
    /**
     * Mass-assignable attributes — explicit whitelist prevents assignment
     * of primary key and any future guarded audit columns.
     *
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'user_id',
        'joined_at',
        'left_at',
    ];

    /**
     * Attribute type casting for consistent runtime types in API layers.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'team_id' => 'integer',
        'user_id' => 'integer',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    /**
     * Team this membership period belongs to.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * User who held membership for this period.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
