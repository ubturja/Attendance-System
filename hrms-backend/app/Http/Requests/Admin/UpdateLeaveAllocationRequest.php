<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates Admin bulk assignment of yearly leave quotas for a user.
 *
 * Accepts a calendar year and an allocations array — one entry per leave type.
 * Admin assignments must be whole days; the DECIMAL(8,2) column still allows
 * fractional taken_days (e.g. 0.5 half-day deductions). remaining_days is never submitted.
 */
class UpdateLeaveAllocationRequest extends ApiFormRequest
{
    /**
     * Bulk upsert rules for yearly leave balance quotas.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Calendar year scoping — all allocations in this request share one year.
            'year' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2100'],

            // One or more leave-type quotas to upsert for the bound user.
            'allocations' => ['required', 'array'],

            // Target leave category for each allocation row.
            'allocations.*.leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],

            // Admin-assigned yearly quota — whole days only (half-day usage is deducted via taken_days).
            'allocations.*.assigned_days' => ['required', 'integer', 'min:0'],
        ];
    }
}
