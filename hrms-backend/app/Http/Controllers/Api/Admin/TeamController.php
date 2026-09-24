<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTeamRequest;
use App\Http\Requests\Admin\UpdateTeamRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin CRUD controller for HRMS team management.
 *
 * Secured at the route layer via auth:sanctum + role:Admin middleware.
 * Soft-deletes archive teams; restore brings them back into the active catalog.
 * PRD: Teams cannot be archived while active users remain assigned.
 */
class TeamController extends Controller
{
    /**
     * List teams for the Admin catalog.
     *
     * Query: ?status=archived → only soft-deleted rows with membership history.
     * Query: ?search=alpha → filters by team_name (LIKE), works with archived status.
     * Default → non-trashed teams with leader + current members.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $isArchived = $request->query('status') === 'archived';

        $query = $isArchived
            ? Team::onlyTrashed()->with(['leader', 'historicalMembers.user', 'teamGroup'])
            : Team::query()->with(['leader', 'users', 'teamGroup']);

        if (is_string($search) && trim($search) !== '') {
            $query->where('team_name', 'like', '%'.trim($search).'%');
        }

        $teams = $query->orderBy('team_name')->get();

        return response()->json([
            'success' => true,
            'message' => $isArchived
                ? 'Archived teams retrieved successfully.'
                : 'Teams retrieved successfully.',
            'data' => $teams,
        ], 200);
    }

    /**
     * Persist a new team record.
     */
    public function store(StoreTeamRequest $request, MessagingService $messaging): JsonResponse
    {
        $validated = $request->validated();
        $createTeamGroup = (bool) ($validated['create_team_group'] ?? false);
        unset($validated['create_team_group']);

        $team = DB::transaction(function () use ($request, $messaging, $validated, $createTeamGroup): Team {
            $team = Team::query()->create($validated);

            if ($createTeamGroup) {
                $actor = $request->user();
                if ($actor instanceof User) {
                    $messaging->ensureTeamGroup($actor, $team);
                }
            }

            return $team;
        });

        $team->load(['leader', 'users', 'teamGroup']);

        return response()->json([
            'success' => true,
            'message' => 'Team created successfully.',
            'data' => $team,
        ], 201);
    }

    /**
     * Retrieve a single team record by primary key.
     */
    public function show(Team $team): JsonResponse
    {
        $team->load(['leader', 'users', 'teamGroup']);

        return response()->json([
            'success' => true,
            'message' => 'Team retrieved successfully.',
            'data' => $team,
        ], 200);
    }

    /**
     * Update team name and/or leader designation.
     */
    public function update(UpdateTeamRequest $request, Team $team, MessagingService $messaging): JsonResponse
    {
        $validated = $request->validated();
        $team->fill($validated);
        $team->save();

        if ($team->wasChanged('team_name')) {
            $actor = $request->user();
            if ($actor instanceof User) {
                $messaging->renameTeamGroup($actor, $team);
            }
        }

        $team->load(['leader', 'users', 'teamGroup']);

        return response()->json([
            'success' => true,
            'message' => 'Team updated successfully.',
            'data' => $team,
        ], 200);
    }

    /**
     * Soft-delete (archive) a team record.
     *
     * Business rule (PRD / SystemArchitecture): block archival when active users
     * are still assigned to the team — team must be empty first.
     */
    public function destroy(Team $team, MessagingService $messaging): JsonResponse
    {
        $hasActiveUsers = $team->users()
            ->where('is_active', true)
            ->exists();

        if ($hasActiveUsers) {
            return response()->json([
                'success' => false,
                'message' => 'The team must be empty before it can be archived.',
            ], 422);
        }

        DB::transaction(function () use ($messaging, $team): void {
            $actor = request()->user();
            if ($actor instanceof User) {
                $messaging->archiveTeamGroup($actor, $team);
            }

            $team->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Team successfully archived.',
            'data' => null,
        ], 200);
    }

    /**
     * Restore a soft-deleted team (Admin only).
     *
     * Clears deleted_at so the team reappears in the active Admin catalog.
     */
    public function restore(int $id, MessagingService $messaging): JsonResponse
    {
        $team = Team::onlyTrashed()->findOrFail($id);
        $team->restore();
        $actor = request()->user();
        if ($actor instanceof User) {
            $messaging->restoreTeamGroup($actor, $team);
        }

        $team->load(['leader', 'users', 'historicalMembers.user', 'teamGroup']);

        return response()->json([
            'success' => true,
            'message' => 'Team restored.',
            'data' => $team,
        ], 200);
    }
}
