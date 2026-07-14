<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates Admin leave type status toggle payloads.
 *
 * Restricted to is_active only — code and name are managed at creation time
 * to preserve historical attendance and balance referential integrity.
 */
class UpdateLeaveTypeRequest extends ApiFormRequest
{
    /**
     * Toggle rules for dynamic dropdown visibility (HighLevelArchitecture.md).
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Required boolean — controls inclusion in GET /api/leave-types dropdown query.
            'is_active' => ['required', 'boolean'],
        ];
    }
}
