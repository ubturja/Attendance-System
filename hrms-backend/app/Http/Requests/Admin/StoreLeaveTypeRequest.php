<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates Admin leave type creation payloads.
 *
 * Accepts leave_type_code, name, and optional is_quota_based.
 * is_active defaults to true at the database layer per ERD.
 */
class StoreLeaveTypeRequest extends ApiFormRequest
{
    /**
     * Creation rules aligned with leave_types table schema (ERD.md).
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Short attendance code (e.g., A, S, N) — globally unique per ERD.
            'leave_type_code' => ['required', 'string', 'max:50', 'unique:leave_types,leave_type_code'],

            // Human-readable label for Admin UI and reports (VARCHAR 191).
            'name' => ['required', 'string', 'max:191'],

            // When false, type appears in attendance dropdowns but not Assign Leave grids.
            'is_quota_based' => ['sometimes', 'boolean'],
        ];
    }
}
