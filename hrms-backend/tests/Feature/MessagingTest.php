<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_employees_cannot_direct_message_each_other(): void
    {
        $team = Team::factory()->create();
        $first = User::factory()->employee()->forTeam($team)->create();
        $second = User::factory()->employee()->forTeam($team)->create();

        Sanctum::actingAs($first);

        $this->postJson('/api/conversations/direct', ['user_id' => $second->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'Employees can message admins only.');
    }

    public function test_employee_can_message_an_admin_and_admins_can_message_each_other(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();

        Sanctum::actingAs($employee);
        $this->postJson('/api/conversations/direct', ['user_id' => $admin->id])
            ->assertOk()
            ->assertJsonPath('data.kind', Conversation::KIND_DIRECT);

        Sanctum::actingAs($admin);
        $this->postJson('/api/conversations/direct', ['user_id' => $otherAdmin->id])
            ->assertOk()
            ->assertJsonPath('data.kind', Conversation::KIND_DIRECT);
    }

    public function test_announcement_send_rules_and_everyone_mention(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/conversations', [
            'kind' => Conversation::KIND_ANNOUNCEMENT,
            'name' => 'Company news',
            'member_ids' => [$employee->id],
        ])->assertCreated();

        $conversationId = (int) $created->json('data.id');
        $this->assertSame(Conversation::SEND_ADMINS_ONLY, $created->json('data.send_permission'));

        Sanctum::actingAs($employee);
        $this->postJson("/api/conversations/{$conversationId}/messages", ['body' => 'Hello'])
            ->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson("/api/conversations/{$conversationId}/join")->assertOk();
        $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => '@everyone please read this',
            'mention_everyone' => true,
        ])->assertCreated()
            ->assertJsonPath('data.mention_everyone', true);

        $this->patchJson("/api/conversations/{$conversationId}", [
            'send_permission' => Conversation::SEND_ALL_MEMBERS,
        ])->assertOk();

        Sanctum::actingAs($employee);
        $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => '@everyone',
            'mention_everyone' => true,
        ])->assertForbidden()
            ->assertJsonPath('message', 'You cannot mention everyone in this group.');

        $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'A normal note',
        ])->assertCreated();
    }

    public function test_team_group_membership_follows_the_employee_team(): void
    {
        $finance = Team::factory()->create(['team_name' => 'Finance']);
        $accounts = Team::factory()->create(['team_name' => 'Accounts']);
        $admin = User::factory()->admin()->create();
        $financeEmployee = User::factory()->employee()->forTeam($finance)->create();
        $accountsEmployee = User::factory()->employee()->forTeam($accounts)->create();

        Sanctum::actingAs($admin);
        $created = $this->postJson("/api/admin/teams/{$finance->id}/group")
            ->assertOk();
        $conversationId = (int) $created->json('data.id');

        $this->postJson("/api/conversations/{$conversationId}/members", [
            'user_ids' => [$accountsEmployee->id],
        ])->assertStatus(422);

        $this->postJson("/api/conversations/{$conversationId}/members", [
            'user_ids' => [$financeEmployee->id],
        ])->assertOk();

        $this->assertTrue(
            ConversationMember::query()
                ->where('conversation_id', $conversationId)
                ->where('user_id', $financeEmployee->id)
                ->whereNull('left_at')
                ->exists()
        );

        $financeEmployee->update(['team_id' => $accounts->id]);

        $this->assertNotNull(
            ConversationMember::query()
                ->where('conversation_id', $conversationId)
                ->where('user_id', $financeEmployee->id)
                ->value('left_at')
        );
    }

    public function test_restoring_a_team_group_keeps_its_history(): void
    {
        $team = Team::factory()->create(['team_name' => 'Finance']);
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);
        $created = $this->postJson("/api/admin/teams/{$team->id}/group")->assertOk();
        $conversationId = (int) $created->json('data.id');

        $this->postJson("/api/conversations/{$conversationId}/join")->assertOk();
        $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'Keep this history',
        ])->assertCreated();

        $this->deleteJson("/api/conversations/{$conversationId}")->assertOk();
        $this->assertSoftDeleted('conversations', ['id' => $conversationId]);

        $restored = $this->postJson("/api/admin/teams/{$team->id}/group")->assertOk();
        $this->assertSame($conversationId, (int) $restored->json('data.id'));
        $this->assertNull($restored->json('data.deleted_at'));

        $this->assertDatabaseHas('chat_messages', [
            'conversation_id' => $conversationId,
            'body' => 'Keep this history',
        ]);
    }

    public function test_leaving_a_team_removes_membership_from_an_archived_team_chat(): void
    {
        $finance = Team::factory()->create(['team_name' => 'Finance']);
        $accounts = Team::factory()->create(['team_name' => 'Accounts']);
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->forTeam($finance)->create();

        Sanctum::actingAs($admin);
        $created = $this->postJson("/api/admin/teams/{$finance->id}/group")->assertOk();
        $conversationId = (int) $created->json('data.id');

        $this->postJson("/api/conversations/{$conversationId}/members", [
            'user_ids' => [$employee->id],
        ])->assertOk();

        $this->deleteJson("/api/conversations/{$conversationId}")->assertOk();

        $employee->update(['team_id' => $accounts->id]);

        $this->assertNotNull(
            ConversationMember::query()
                ->where('conversation_id', $conversationId)
                ->where('user_id', $employee->id)
                ->value('left_at')
        );

        $this->postJson("/api/admin/teams/{$finance->id}/group")->assertOk();

        $this->assertNotNull(
            ConversationMember::query()
                ->where('conversation_id', $conversationId)
                ->where('user_id', $employee->id)
                ->value('left_at')
        );
    }

    public function test_archiving_and_restoring_a_team_does_the_same_to_its_chat(): void
    {
        $team = Team::factory()->create(['team_name' => 'Finance']);
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);
        $created = $this->postJson("/api/admin/teams/{$team->id}/group")->assertOk();
        $conversationId = (int) $created->json('data.id');

        $this->deleteJson("/api/admin/teams/{$team->id}")->assertOk();
        $this->assertSoftDeleted('conversations', ['id' => $conversationId]);

        $this->patchJson("/api/admin/teams/{$team->id}/restore")->assertOk();
        $this->assertNull(Conversation::withTrashed()->find($conversationId)?->deleted_at);
    }

    public function test_team_rename_renames_the_group_and_direct_rename_is_rejected(): void
    {
        $team = Team::factory()->create(['team_name' => 'Finance']);
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);
        $created = $this->postJson("/api/admin/teams/{$team->id}/group")->assertOk();
        $conversationId = (int) $created->json('data.id');

        $this->putJson("/api/admin/teams/{$team->id}", [
            'team_name' => 'Finance Ops',
            'team_leader_id' => null,
        ])->assertOk();

        $this->assertSame('Finance Ops', Conversation::query()->find($conversationId)?->name);

        $this->patchJson("/api/conversations/{$conversationId}", [
            'name' => 'Something else',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'A team group name always matches the team name.');
    }

    public function test_deleted_messages_stay_hidden_unless_an_admin_reveals_them(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();

        Sanctum::actingAs($admin);
        $created = $this->postJson('/api/conversations', [
            'kind' => Conversation::KIND_GENERAL,
            'name' => 'General',
            'member_ids' => [$admin->id, $employee->id],
        ])->assertCreated();
        $conversationId = (int) $created->json('data.id');

        $sent = $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'Secret plan',
        ])->assertCreated();
        $messageId = (int) $sent->json('data.id');

        $this->deleteJson("/api/messages/{$messageId}")->assertOk()
            ->assertJsonPath('data.placeholder', 'This message was deleted')
            ->assertJsonPath('data.body', null);

        $this->getJson("/api/messages/{$messageId}/reveal")
            ->assertOk()
            ->assertJsonPath('data.body', 'Secret plan');

        Sanctum::actingAs($employee);
        $this->getJson("/api/messages/{$messageId}/reveal")->assertForbidden();

        $edited = $this->postJson("/api/conversations/{$conversationId}/messages", [
            'body' => 'Original',
        ])->assertCreated();

        Sanctum::actingAs($employee);
        $this->patchJson('/api/messages/'.(int) $edited->json('data.id'), [
            'body' => 'Updated text',
        ])->assertOk()
            ->assertJsonPath('data.body', 'Updated text');

        $this->assertNotNull(ChatMessage::query()->find((int) $edited->json('data.id'))?->edited_at);
    }

    public function test_admin_must_join_before_reading_and_a_message_can_be_files_only(): void
    {
        Storage::fake('local');

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/conversations', [
            'kind' => Conversation::KIND_GENERAL,
            'name' => 'Files',
        ])->assertCreated();
        $conversationId = (int) $created->json('data.id');

        $this->getJson("/api/conversations/{$conversationId}/messages")->assertForbidden();
        $this->postJson("/api/conversations/{$conversationId}/join")->assertOk();

        $this->postJson("/api/conversations/{$conversationId}/messages", [
            'files' => [UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')],
        ])->assertCreated()
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.attachments.0.original_name', 'notes.pdf');
    }

    public function test_a_message_cannot_exceed_the_configured_size_limit(): void
    {
        Storage::fake('local');
        config(['messaging.max_message_bytes' => 100]);

        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/conversations', [
            'kind' => Conversation::KIND_GENERAL,
            'name' => 'Limits',
            'member_ids' => [$admin->id],
        ])->assertCreated();

        $this->postJson('/api/conversations/'.(int) $created->json('data.id').'/messages', [
            'files' => [UploadedFile::fake()->create('big.bin', 1)],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'One message cannot be larger than 1GB.');
    }

    public function test_non_members_cannot_see_a_group_in_their_list(): void
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();

        Sanctum::actingAs($admin);
        $this->postJson('/api/conversations', [
            'kind' => Conversation::KIND_GENERAL,
            'name' => 'Private general',
        ])->assertCreated();

        Sanctum::actingAs($employee);
        $names = collect($this->getJson('/api/conversations')->assertOk()->json('data'))->pluck('name');
        $this->assertFalse($names->contains('Private general'));
    }
}
