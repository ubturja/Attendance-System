<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD Entity: attendance_logs
 *
 * Daily attendance fact table used by pivot reporting engines. Stores the
 * team context at log time for historical accuracy when users change teams.
 * Non-leave codes (W, O, X) persist with leave_type_id = NULL per business rules.
 *
 * @property int $id
 * @property int $user_id
 * @property int $team_id
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $submitted_code
 * @property int|null $leave_type_id
 */
class AttendanceLog extends Model
{
    /**
     * Explicit table name — matches ERD table `attendance_logs`.
     */
    protected $table = 'attendance_logs';

    /**
     * ERD schema does not define created_at / updated_at columns.
     */
    public $timestamps = false;

    /**
     * Mass-assignable attributes — explicit whitelist prevents assignment
     * of primary key and any future guarded audit columns.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'team_id',
        'date',
        'submitted_code',
        'leave_type_id',
    ];

    /**
     * Attribute type casting for consistent runtime types in API layers.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'team_id' => 'integer',
        'date' => 'date',
        'leave_type_id' => 'integer',
    ];

    /**
     * ERD: users.id → attendance_logs.user_id (1:M).
     * The employee whose daily attendance is recorded.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * ERD: teams.id → attendance_logs.team_id (1:M).
     * Team snapshot at the time of attendance entry.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    /**
     * ERD: leave_types.id → attendance_logs.leave_type_id (1:M, nullable).
     * Null when the attendance code is a non-leave type (W, O, X).
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
