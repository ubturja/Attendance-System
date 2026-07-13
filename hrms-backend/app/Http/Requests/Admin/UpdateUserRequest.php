<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates Admin user update payloads.
 *
 * PRD: name, email, and passport_number are immutable after creation and are
 * intentionally excluded from these rules to prevent post-create modification.
 */
class UpdateUserRequest extends ApiFormRequest
{
    /**
     * Partial update rules — only mutable profile and assignment fields per PRD.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // RBAC role may be changed by Admin (e.g., promote to Admin).
            'job_title' => ['sometimes', 'string', Rule::in(['Admin', 'Employee'])],

            // Demographic and contact fields remain editable.
            'nationality' => ['sometimes', 'nullable', 'string'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string'],

            // PRD explicitly allows Admin to set/update work_type.
            'work_type' => ['sometimes', 'nullable', 'string', 'max:100'],

            // Soft deactivation without deleting historical attendance rows.
            'is_active' => ['sometimes', 'boolean'],

            // Move user between teams — nullable clears assignment.
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
        ];
    }
}
