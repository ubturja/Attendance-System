<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserYearlyLeaveRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ERD Entity: user_yearly_leave_records
 *
 * Normalized yearly leave balance ledger — one row per user, leave type, and
 * calendar year. Fractional half-days are stored as DECIMAL(8,2). Remaining
 * balance is computed at runtime (assigned_days - taken_days), never persisted.
 *
 * @property int $id
 * @property int $user_id
 * @property int $leave_type_id
 * @property int $year
 * @property float $assigned_days
 * @property float $taken_days
 * @property-read float $remaining_days Computed accessor — never persisted
 */
class UserYearlyLeaveRecord extends Model
{
    /** @use HasFactory<UserYearlyLeaveRecordFactory> */
    use HasFactory;

    /**
     * Explicit table name — Laravel's default snake_case pluralization would
     * not resolve correctly for this compound entity name.
     */
    protected $table = 'user_yearly_leave_records';

    /**
     * ERD schema does not define created_at / updated_at columns.
     */
    public $timestamps = false;

    /**
     * Append computed attributes to JSON serialization for API responses.
     *
     * @var list<string>
     */
    protected $appends = [
        'remaining_days',
    ];

    /**
     * Mass-assignable attributes — explicit whitelist prevents assignment
     * of primary key and any future guarded audit columns.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'leave_type_id',
        'year',
        'assigned_days',
        'taken_days',
    ];

    /**
     * Attribute type casting for consistent runtime types in API layers.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'leave_type_id' => 'integer',
        'year' => 'integer',
        'assigned_days' => 'float',
        'taken_days' => 'float',
    ];

    /**
     * ERD: users.id → user_yearly_leave_records.user_id (1:M).
     * The employee who owns this yearly balance allocation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * ERD: leave_types.id → user_yearly_leave_records.leave_type_id (1:M).
     * The leave category this balance row tracks.
     */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /**
     * Runtime remaining balance — computed accessor, NEVER stored in the database.
     *
     * Mathematical logic (SystemArchitecture.md):
     *   remaining_days = assigned_days − taken_days
     *
     * Both operands are DECIMAL(8,2) fractions (e.g., 14.50 assigned, 0.50 taken
     * after an AO half-day variant) cast to float for API serialization.
     * Result is rounded to 2 decimal places to match DB precision.
     */
    public function getRemainingDaysAttribute(): float
    {
        $assigned = (float) $this->assigned_days;
        $taken = (float) $this->taken_days;

        // Core allocation formula — negative results indicate over-consumption.
        return round($assigned - $taken, 2);
    }
}
