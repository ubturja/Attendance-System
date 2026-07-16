<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * ERD Entity: users
 *
 * Core identity and authentication model for Admin and Employee accounts.
 * Integrates with Laravel Sanctum (token auth) and CheckRole middleware via
 * the `job_title` RBAC discriminator. Soft-deleted users are excluded from
 * default queries and authentication automatically via SoftDeletes.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $job_title
 * @property string|null $nationality
 * @property string $passport_number
 * @property string|null $phone_number
 * @property string|null $address
 * @property string|null $work_type
 * @property bool $is_active
 * @property int|null $team_id
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
    use SoftDeletes;

    /**
     * ERD schema does not define created_at / updated_at columns.
     * SoftDeletes still manages deleted_at independently.
     */
    public $timestamps = false;

    /**
     * Mass-assignable attributes — explicit whitelist prevents Mass Assignment
     * of primary key and any future guarded audit columns.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'job_title',
        'nationality',
        'passport_number',
        'phone_number',
        'address',
        'work_type',
        'is_active',
        'team_id',
    ];

    /**
     * Attributes excluded from JSON serialization (credential protection).
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Attribute type casting for consistent runtime types in API layers.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'team_id' => 'integer',
        'password' => 'hashed',
    ];

    /**
     * ERD: teams.id → users.team_id (M:1).
     * The team this employee is currently assigned to, if any.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * ERD: users.id → teams.team_leader_id (1:1 Leader).
     * The team this user leads, when designated as team leader.
     */
    public function ledTeam(): HasOne
    {
        return $this->hasOne(Team::class, 'team_leader_id');
    }

    /**
     * ERD: users.id → user_yearly_leave_records.user_id (1:M).
     * Yearly leave balance allocations for this employee.
     */
    public function yearlyLeaveRecords(): HasMany
    {
        return $this->hasMany(UserYearlyLeaveRecord::class, 'user_id');
    }

    /**
     * ERD: users.id → attendance_logs.user_id (1:M).
     * Daily attendance history for this employee.
     */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'user_id');
    }
}
