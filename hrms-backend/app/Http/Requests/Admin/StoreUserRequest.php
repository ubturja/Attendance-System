<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates Admin user creation payloads.
 *
 * Enforces ERD column constraints and PRD immutability boundaries at creation time.
 * Password is validated as plaintext here; hashing occurs in UserController via Hash facade.
 */
class StoreUserRequest extends ApiFormRequest
{
    /**
     * Creation rules aligned with users table schema (ERD.md).
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Display name — required on account provisioning (Admin-only, no self-registration).
            'name' => ['required', 'string', 'max:255'],

            // Unique login identifier; VARCHAR(191) for utf8mb4 index compatibility.
            'email' => ['required', 'string', 'email', 'max:191', 'unique:users,email'],

            // Plaintext credential — minimum length enforced before Hash::make in controller.
            'password' => ['required', 'string', 'min:8'],

            // RBAC discriminator — must match ERD ENUM values exactly.
            'job_title' => ['required', 'string', Rule::in(['Admin', 'Employee'])],

            // Optional demographic TEXT field.
            'nationality' => ['nullable', 'string'],

            // Immutable business identifier after creation (PRD); unique at insert time.
            'passport_number' => ['required', 'string', 'max:100', 'unique:users,passport_number'],

            // Optional contact fields.
            'phone_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],

            // Admin-managed employment classification.
            'work_type' => ['nullable', 'string', 'max:100'],

            // Operational flag — defaults to true at DB layer if omitted.
            'is_active' => ['sometimes', 'boolean'],

            // Optional team assignment — FK must reference an existing teams.id row.
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
        ];
    }
}
