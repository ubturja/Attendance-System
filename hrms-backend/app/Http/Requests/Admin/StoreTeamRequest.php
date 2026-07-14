<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

/**
 * Validates Admin team creation payloads.
 *
 * Enforces unique team_name and optional team_leader_id FK integrity.
 */
class StoreTeamRequest extends ApiFormRequest
{
    /**
     * Creation rules aligned with teams table schema (ERD.md).
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            // Unique organizational label — VARCHAR(191) per ERD utf8mb4 index limit.
            'team_name' => ['required', 'string', 'max:191', 'unique:teams,team_name'],

            // Optional leader designation — must reference an existing user if provided.
            'team_leader_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
