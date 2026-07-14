<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTeamRequest;
use App\Http\Requests\Admin\UpdateTeamRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

/**
 * Admin CRUD controller for HRMS team management.
 *
 * Secured at the route layer via auth:sanctum + role:Admin middleware.
 * PRD: Teams cannot be deleted while active users remain assigned.
 */
class TeamController extends Controller
{
    /**
     * List all teams with leader and member relationships eager-loaded.
     */
    public function index(): JsonResponse
    {
        // Eager-load leader + members to avoid N+1 on Admin team management screens.
        $teams = Team::query()
            ->with(['leader', 'users'])
            ->orderBy('team_name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Teams retrieved successfully.',
            'data' => $teams,
        ], 200);
    }

    /**
     * Persist a new team record.
     */
    public function store(StoreTeamRequest $request): JsonResponse
    {
        // StoreTeamRequest validates unique team_name and optional team_leader_id FK.
        $validated = $request->validated();

        // Mass assignment protected by Team::$fillable whitelist.
        $team = Team::query()->create($validated);

        $team->load(['leader', 'users']);

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
        $team->load(['leader', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Team retrieved successfully.',
            'data' => $team,
        ], 200);
    }

    /**
     * Update team name and/or leader designation.
     */
    public function update(UpdateTeamRequest $request, Team $team): JsonResponse
    {
        // UpdateTeamRequest scopes team_name uniqueness to the current record.
        // Accepts nullable team_leader_id (exists:users,id) to assign or clear the leader.
        $validated = $request->validated();

        // fill() respects $fillable — only team_name and team_leader_id are writable.
        $team->fill($validated);

        // Persist changes to MySQL teams table.
        $team->save();

        $team->load(['leader', 'users']);

        return response()->json([
            'success' => true,
            'message' => 'Team updated successfully.',
            'data' => $team,
        ], 200);
    }

    /**
     * Remove a team record.
     *
     * Business rule (PRD / SystemArchitecture): block deletion when active users
     * are still assigned to the team — team must be empty first.
     */
    public function destroy(Team $team): JsonResponse
    {
        // Query active members — is_active=true per ERD operational flag.
        $hasActiveUsers = $team->users()
            ->where('is_active', true)
            ->exists();

        // Abort before DELETE when active assignments remain — 422 per API contract.
        if ($hasActiveUsers) {
            return response()->json([
                'success' => false,
                'message' => 'The team must be empty before it can be deleted.',
            ], 422);
        }

        // Safe to delete — no active users attached; DB restrict FKs on attendance_logs may still apply.
        $team->delete();

        return response()->json([
            'success' => true,
            'message' => 'Team deleted successfully.',
        ], 200);
    }
}
