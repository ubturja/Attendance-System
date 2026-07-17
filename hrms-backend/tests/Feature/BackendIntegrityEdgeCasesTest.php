<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMembershipHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackendIntegrityEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_team_rejects_leader_who_is_not_a_team_member(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::factory()->create(['team_name' => 'Alpha Team']);
        $outsider = User::factory()->employee()->create(['team_id' => null]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/teams/{$team->id}", [
            'team_name' => 'Alpha Team',
            'team_leader_id' => $outsider->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('success', false);
        $response->assertJsonValidationErrors(['team_leader_id']);
        $this->assertNull($team->fresh()->team_leader_id);
    }

    public function test_update_team_accepts_leader_who_belongs_to_the_team(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::factory()->create(['team_name' => 'Alpha Team']);
        $member = User::factory()->employee()->forTeam($team)->create();

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/teams/{$team->id}", [
            'team_name' => 'Alpha Team',
            'team_leader_id' => $member->id,
        ]);

        $response->assertOk();
        $this->assertSame($member->id, $team->fresh()->team_leader_id);
    }

    public function test_store_team_rejects_team_leader_before_membership_exists(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create(['team_id' => null]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/teams', [
            'team_name' => 'Brand New Team',
            'team_leader_id' => $employee->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['team_leader_id']);
        $this->assertDatabaseMissing('teams', ['team_name' => 'Brand New Team']);
    }

    public function test_soft_deleting_user_closes_open_membership_history(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create();

        $this->assertDatabaseHas('team_membership_histories', [
            'user_id' => $employee->id,
            'team_id' => $team->id,
            'left_at' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/admin/users/{$employee->id}");

        $response->assertOk();

        $history = TeamMembershipHistory::query()
            ->where('user_id', $employee->id)
            ->where('team_id', $team->id)
            ->first();

        $this->assertNotNull($history);
        $this->assertNotNull($history->left_at);
    }

    public function test_deactivating_user_closes_open_membership_history(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::factory()->create();
        $employee = User::factory()->employee()->forTeam($team)->create(['is_active' => true]);

        Sanctum::actingAs($admin);

        $response = $this->putJson("/api/admin/users/{$employee->id}", [
            'is_active' => false,
        ]);

        $response->assertOk();

        $history = TeamMembershipHistory::query()
            ->where('user_id', $employee->id)
            ->whereNull('left_at')
            ->first();

        $this->assertNull($history);

        $closed = TeamMembershipHistory::query()
            ->where('user_id', $employee->id)
            ->where('team_id', $team->id)
            ->first();

        $this->assertNotNull($closed);
        $this->assertNotNull($closed->left_at);
    }

    public function test_profile_rejects_malformed_date_with_422(): void
    {
        $user = User::factory()->employee()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile?date=invalid');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date']);
    }

    public function test_profile_accepts_valid_date_format(): void
    {
        $user = User::factory()->employee()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/profile?date=2026-07-15');

        $response->assertOk();
        $response->assertJsonPath('data.attendance_date', '2026-07-15');
    }
}
