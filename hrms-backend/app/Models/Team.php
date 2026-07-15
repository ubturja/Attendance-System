<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ERD Entity: teams
 *
 * Organizational unit that groups employees and snapshots team context on
 * attendance logs. A team may optionally designate one user as team leader
 * via `team_leader_id` (1:1 leader assignment per ERD). Soft-deleted teams
 * are hidden from Admin catalogs but remain readable via withTrashed() for
 * historical attendance and membership audit trails.
 *
 * @property int $id
 * @property string $team_name
 * @property int|null $team_leader_id
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * ERD schema does not define created_at / updated_at columns.
     * SoftDeletes still manages deleted_at independently.
     */
    public $timestamps = false;

    /**
     * Mass-assignable attributes — explicit whitelist prevents assignment
     * of primary key and any future guarded audit columns.
     *
     * @var list<string>
     */
    protected $fillable = [
        'team_name',
        'team_leader_id',
    ];

    /**
     * Attribute type casting for consistent runtime types in API layers.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'team_leader_id' => 'integer',
    ];

    /**
     * ERD: teams.id → users.team_id (1:M).
     * All employees currently assigned to this team.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'team_id');
    }

    /**
     * ERD: users.id → teams.team_leader_id (1:1 Leader).
     * The user designated as leader for this team, if any.
     */
    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }

    /**
     * Alias for teamLeader() — exposed in API JSON as `leader` for Admin UI.
     */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_leader_id');
    }

    /**
     * ERD: teams.id → attendance_logs.team_id (1:M).
     * Historical attendance rows captured under this team's context.
     */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'team_id');
    }

    /**
     * Audit: teams.id → team_membership_histories.team_id (1:M).
     * Join/leave periods for users previously or currently on this team.
     */
    public function historicalMembers(): HasMany
    {
        return $this->hasMany(TeamMembershipHistory::class, 'team_id');
    }
}
