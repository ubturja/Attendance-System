<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TeamMembershipHistory;
use App\Models\User;

/**
 * Records team membership join/leave windows whenever users.team_id is set or changes.
 *
 * Open periods keep left_at NULL until the next reassignment, soft-delete, or
 * deactivation closes them.
 */
class UserObserver
{
    /**
     * Handle the User "created" event.
     *
     * Users provisioned with an initial team_id must open a history window
     * immediately — the updated() handler only fires on later reassignments.
     */
    public function created(User $user): void
    {
        if ($user->team_id === null) {
            return;
        }

        TeamMembershipHistory::query()->create([
            'user_id' => $user->id,
            'team_id' => $user->team_id,
            'joined_at' => now(),
            'left_at' => null,
        ]);
    }

    /**
     * Handle the User "updated" event.
     *
     * When team_id changes:
     * 1. Close the prior open TeamMembershipHistory row (set left_at = now).
     * 2. Open a new row for the destination team (joined_at = now).
     *
     * When is_active flips to false, close any open membership window so
     * archived team audit trails do not show deactivated users as current members.
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged('is_active') && ! $user->is_active) {
            $this->closeOpenMembershipWindows($user);
        }

        if (! $user->wasChanged('team_id')) {
            return;
        }

        /** @var int|null $previousTeamId */
        $previousTeamId = $user->getOriginal('team_id');
        /** @var int|null $newTeamId */
        $newTeamId = $user->team_id;

        // Close the open membership window on the previous team, if any.
        if ($previousTeamId !== null) {
            TeamMembershipHistory::query()
                ->where('user_id', $user->id)
                ->where('team_id', $previousTeamId)
                ->whereNull('left_at')
                ->orderByDesc('joined_at')
                ->limit(1)
                ->update(['left_at' => now()]);
        }

        // Start a new membership window on the destination team, if any.
        if ($newTeamId !== null) {
            TeamMembershipHistory::query()->create([
                'team_id' => $newTeamId,
                'user_id' => $user->id,
                'joined_at' => now(),
                'left_at' => null,
            ]);
        }
    }

    /**
     * Handle the User "deleted" event (soft-delete / offboarding).
     *
     * Close every open membership window so archived team history does not
     * treat the offboarded user as an active member, and revoke all Sanctum
     * tokens so the offboarded user cannot keep using existing sessions.
     */
    public function deleted(User $user): void
    {
        $this->closeOpenMembershipWindows($user);
        $user->tokens()->delete();
    }

    /**
     * Stamp left_at = now() on every open membership history row for this user.
     */
    private function closeOpenMembershipWindows(User $user): void
    {
        TeamMembershipHistory::query()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);
    }
}
