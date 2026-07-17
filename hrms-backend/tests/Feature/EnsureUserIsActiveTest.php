<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class EnsureUserIsActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_with_valid_token_can_access_protected_api(): void
    {
        $user = User::factory()->employee()->create(['is_active' => true]);
        $token = $user->createToken('hrms-api-token')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/profile');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());
    }

    public function test_inactive_user_token_is_revoked_and_request_is_forbidden(): void
    {
        $user = User::factory()->employee()->create(['is_active' => true]);
        $plainTextToken = $user->createToken('hrms-api-token')->plainTextToken;

        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());

        $user->forceFill(['is_active' => false])->save();

        $response = $this->withToken($plainTextToken)->getJson('/api/profile');

        $response->assertForbidden();
        $response->assertJson([
            'message' => 'Your account has been deactivated. Access revoked.',
        ]);

        $this->assertSame(
            0,
            PersonalAccessToken::query()->where('tokenable_id', $user->id)->count(),
            'All Sanctum tokens for the deactivated user must be revoked.',
        );
    }

    public function test_soft_deleted_user_cannot_use_existing_token(): void
    {
        $user = User::factory()->employee()->create(['is_active' => true]);
        $token = $user->createToken('hrms-api-token')->plainTextToken;

        $user->delete();

        $response = $this->withToken($token)->getJson('/api/profile');

        // SoftDeletes excludes the user from Sanctum's tokenable resolve → unauthenticated,
        // or EnsureUserIsActive returns 403 if the principal is still resolved.
        $this->assertTrue(
            in_array($response->status(), [401, 403], true),
            'Expected soft-deleted user request to be rejected with 401 or 403.',
        );
    }

    public function test_login_route_is_not_blocked_by_active_user_middleware(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        // Credentials fail — proves middleware passed through (not a 403 deactivate gate).
        $response->assertUnauthorized();
        $response->assertJsonPath('success', false);
    }
}
