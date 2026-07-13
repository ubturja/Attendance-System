<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Validates Admin adjustments to yearly leave balance rows.
 *
 * Accepts assigned_days and/or taken_days — at least one must be present.
 * Values map to DECIMAL(8,2) columns; remaining_days is never submitted.
 */
class UpdateLeaveAllocationRequest extends ApiFormRequest
{
    /**
     * Partial update rules for DECIMAL(8,2) balance columns.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Target leave category row for the bound user (normalized composite key).
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],

            // Calendar year scoping — one balance row per (user, leave_type, year).
            'year' => ['required', 'integer', 'digits:4', 'min:2000', 'max:2100'],

            // Admin-assigned yearly quota (supports fractional values e.g., 14.5).
            'assigned_days' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2'],

            // Consumption tally — may be adjusted by Admin during corrections.
            'taken_days' => ['sometimes', 'required', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }

    /**
     * Ensure at least one balance field is supplied for a meaningful update.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('assigned_days') && ! $this->has('taken_days')) {
                $validator->errors()->add(
                    'assigned_days',
                    'At least one of assigned_days or taken_days must be provided.'
                );
            }
        });
    }
}
