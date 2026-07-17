<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Validates Admin team update payloads.
 *
 * Supports renaming teams and reassigning team leaders (status-only role per PRD).
 * team_leader_id must reference a user whose current users.team_id matches this team.
 */
class UpdateTeamRequest extends ApiFormRequest
{
    /**
     * Partial update rules with unique team_name scoped to the current record.
     *
     * @return array<string, list<\Illuminate\Contracts\Validation\ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('team');

        return [
            // Allow rename while preserving uniqueness across other teams.
            'team_name' => [
                'sometimes',
                'string',
                'max:191',
                Rule::unique('teams', 'team_name')->ignore($team->id),
            ],

            // Reassign or clear team leader — nullable removes leader designation.
            'team_leader_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * Ensure the designated leader is an active member of this team.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $leaderId = $this->input('team_leader_id');

            if ($leaderId === null || $leaderId === '') {
                return;
            }

            /** @var Team $team */
            $team = $this->route('team');

            $leader = User::query()->find((int) $leaderId);

            if ($leader === null || $leader->team_id === null || (int) $leader->team_id !== (int) $team->id) {
                $validator->errors()->add(
                    'team_leader_id',
                    'The selected team leader must belong to this team.',
                );
            }
        });
    }
}
