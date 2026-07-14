<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Models\Team;
use Illuminate\Validation\Rule;

/**
 * Validates Admin team update payloads.
 *
 * Supports renaming teams and reassigning team leaders (status-only role per PRD).
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
}
