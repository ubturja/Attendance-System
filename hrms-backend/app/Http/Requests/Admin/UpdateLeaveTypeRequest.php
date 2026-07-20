<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates Admin leave type update payloads.
 *
 * Accepts is_active and/or is_quota_based — code and name stay immutable
 * to preserve historical attendance and balance referential integrity.
 */
class UpdateLeaveTypeRequest extends ApiFormRequest
{
    /**
     * Toggle rules for dropdown visibility and quota participation.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Controls inclusion in GET /api/leave-types attendance dropdown.
            'is_active' => ['sometimes', 'boolean'],

            // Controls inclusion in Assign Leave / user balance grids.
            'is_quota_based' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->exists('is_active') && ! $this->exists('is_quota_based')) {
                $validator->errors()->add(
                    'is_active',
                    'At least one of is_active or is_quota_based must be provided.',
                );
            }
        });
    }
}
