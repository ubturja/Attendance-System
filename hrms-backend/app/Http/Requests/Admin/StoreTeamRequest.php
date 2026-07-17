<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Contracts\Validation\Validator;

/**
 * Validates Admin team creation payloads.
 *
 * Enforces unique team_name and optional team_leader_id FK integrity.
 * Leaders cannot be assigned at create time because the team has no members yet —
 * designate a leader after assigning users via UpdateTeamRequest.
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

    /**
     * Reject a team_leader_id on create — no members can belong to a team that
     * does not exist yet, so leadership assignment must happen after membership.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $leaderId = $this->input('team_leader_id');

            if ($leaderId === null || $leaderId === '') {
                return;
            }

            $validator->errors()->add(
                'team_leader_id',
                'The selected team leader must belong to this team. Assign the user to the team first, then designate them as leader.',
            );
        });
    }
}
