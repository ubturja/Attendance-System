<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LeaveTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ERD Entity: leave_types
 *
 * Admin-managed catalog of leave categories (e.g., A = Annual, S = Sick).
 * Powers dynamic attendance dropdowns and yearly balance allocations.
 * Soft-deleted rows are hidden from Admin/Employee catalogs but remain
 * readable in historical reports via withTrashed().
 *
 * @property int $id
 * @property string $leave_type_code
 * @property string $name
 * @property bool $is_active
 * @property bool $is_quota_based
 * @property bool $requires_allocation
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class LeaveType extends Model
{
    /** @use HasFactory<LeaveTypeFactory> */
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
        'leave_type_code',
        'name',
        'is_active',
        'is_quota_based',
        'requires_allocation',
    ];

    /**
     * Attribute type casting for consistent runtime types in API layers.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_quota_based' => 'boolean',
        'requires_allocation' => 'boolean',
    ];

    /**
     * ERD: leave_types.id → user_yearly_leave_records.leave_type_id (1:M).
     * Yearly balance rows allocated against this leave category.
     */
    public function yearlyLeaveRecords(): HasMany
    {
        return $this->hasMany(UserYearlyLeaveRecord::class, 'leave_type_id');
    }

    /**
     * ERD: leave_types.id → attendance_logs.leave_type_id (1:M).
     * Attendance entries referencing this leave type (nullable on log when W/O/X).
     */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'leave_type_id');
    }
}
