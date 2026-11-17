<?php

namespace Tests\Feature\Auth;

use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MemberManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['inertia.ssr.enabled' => false]);
        $this->owner = $this->member('owner');
        $this->login($this->owner);
    }

    private function member(string $role): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->assignRole($role);
        $user->passkeys()->create(['name' => 'key', 'credential_id' => 'member-key-'.$user->id, 'credential' => []]);

        return $user;
    }

    private function login(User $user): void
    {
        $this->actingAs($user)->withSession(['security.totp_user' => $user->id, 'auth.password_confirmed_at' => time()]);
    }

    public function test_owner_can_change_role_atomically_and_replay_creates_one_audit_entry(): void
    {
        $target = $this->member('member');
        $data = ['role' => 'researcher', 'membership_status' => 'approved'];
        $this->patch('/admin/members/'.$target->id, $data)->assertRedirect();
        $this->patch('/admin/members/'.$target->id, $data)->assertRedirect();
        $this->assertSame(['researcher'], $target->fresh()->getRoleNames()->all());
        $this->assertDatabaseCount('audit_entries', 1);
        $audit = DB::table('audit_entries')->first();
        $this->assertSame('member.updated', $audit->action);
        $this->assertSame($this->owner->id, $audit->user_id);
        $metadata = json_decode($audit->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['member'], $metadata['old']['roles']);
        $this->assertSame(['researcher'], $metadata['new']['roles']);
    }

    public function test_nonowners_cannot_manage_members_and_role_status_validation_is_closed(): void
    {
        $target = $this->member('member');
        $this->patchJson('/admin/members/'.$target->id, ['role' => 'superadmin', 'membership_status' => 'approved'])->assertUnprocessable();
        $this->patchJson('/admin/members/'.$target->id, ['role' => 'member', 'membership_status' => 'pending'])->assertUnprocessable();
        $this->login($target);
        $this->patch('/admin/members/'.$this->owner->id, ['role' => 'member', 'membership_status' => 'revoked'])->assertForbidden();
        $this->assertTrue($this->owner->fresh()->hasRole('owner'));
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_owner_cannot_revoke_or_demote_self_and_serialized_cross_demotion_is_denied(): void
    {
        $this->patch('/admin/members/'.$this->owner->id, ['role' => 'owner', 'membership_status' => 'revoked'])->assertForbidden();
        $this->patch('/admin/members/'.$this->owner->id, ['role' => 'member', 'membership_status' => 'approved'])->assertForbidden();
        $second = $this->member('owner');
        $this->patch('/admin/members/'.$second->id, ['role' => 'member', 'membership_status' => 'approved'])->assertRedirect();
        $this->login($second);
        $this->patch('/admin/members/'.$this->owner->id, ['role' => 'member', 'membership_status' => 'approved'])->assertForbidden();
        $this->assertSame(1, User::role('owner')->where('membership_status', 'approved')->count());
    }

    public function test_restore_preserves_credentials_content_and_enrollment_without_reinvite(): void
    {
        $target = $this->member('researcher');
        $session = ResearchSession::create(['user_id' => $target->id, 'title' => 'Retained private work']);
        $before = $target->getRawOriginal();
        $this->patch('/admin/members/'.$target->id, ['role' => 'researcher', 'membership_status' => 'revoked'])->assertRedirect();
        $this->patch('/admin/members/'.$target->id, ['role' => 'researcher', 'membership_status' => 'approved'])->assertRedirect();
        foreach (['password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $field) {
            $this->assertSame($before[$field], $target->fresh()->getRawOriginal($field));
        }
        $this->assertDatabaseCount('passkeys', 2);
        $this->assertDatabaseCount('invitations', 0);
        $this->assertSame($target->id, $session->fresh()->user_id);
        $this->login($target);
        $this->get('/research?session='.$session->id)->assertOk();
        $this->login($this->owner);
        $this->get('/research?session='.$session->id)->assertNotFound();
    }

    public function test_loss_of_research_access_persists_durable_cancellation_and_changes_api_access_immediately(): void
    {
        $target = $this->member('researcher');
        $session = ResearchSession::create(['user_id' => $target->id, 'title' => 'Active work']);
        $runs = [];
        foreach (['queued', 'running', 'waiting', 'completed'] as $status) {
            $runs[$status] = ResearchRun::create(['user_id' => $target->id, 'research_session_id' => $session->id, 'idempotency_key' => 'member-'.$status, 'prompt' => 'Private prompt', 'status' => $status]);
        }
        $this->patch('/admin/members/'.$target->id, ['role' => 'member', 'membership_status' => 'approved'])->assertRedirect();
        $this->assertSame('cancelled', $runs['queued']->fresh()->status);
        $this->assertNotNull($runs['queued']->fresh()->completed_at);
        foreach (['running', 'waiting'] as $status) {
            $this->assertSame('cancel_requested', $runs[$status]->fresh()->status);
            $this->assertNull($runs[$status]->fresh()->completed_at);
        }
        $this->assertSame('completed', $runs['completed']->fresh()->status);
        $this->login($target);
        $this->get('/research')->assertForbidden();
        Http::assertNothingSent();
        config(['trading.bridge_url' => 'http://bridge.test', 'trading.bridge_token' => str_repeat('x', 40)]);
        Http::fake(['bridge.test/*' => Http::response(['error' => 'offline'], 503)]);
        $this->artisan('trading:reconcile')->assertFailed();
        $this->assertSame('cancel_requested', $runs['running']->fresh()->status);
        $this->assertNull($runs['running']->fresh()->completed_at);
    }

    public function test_admin_update_requires_recent_confirmation_and_revocation_denies_the_next_request(): void
    {
        $target = $this->member('researcher');
        $data = ['role' => 'researcher', 'membership_status' => 'revoked'];
        $this->withSession(['auth.password_confirmed_at' => 0])->patchJson('/admin/members/'.$target->id, $data)->assertStatus(423);
        $this->login($this->owner);
        $this->patch('/admin/members/'.$target->id, $data)->assertRedirect();
        $this->login($target);
        $this->getJson('/research')->assertForbidden();
        $this->assertGuest();
    }

    public function test_actor_is_revalidated_after_taking_the_shared_owner_lock(): void
    {
        $target = $this->member('member');
        $roleId = Role::findByName('owner', 'web')->id;
        $lostRole = false;
        DB::listen(function (QueryExecuted $query) use (&$lostRole, $roleId) {
            if (! $lostRole && str_contains($query->sql, 'from "roles"') && in_array('owner', $query->bindings, true)) {
                $lostRole = true;
                DB::table('model_has_roles')->where('model_id', $this->owner->id)->where('role_id', $roleId)->delete();
            }
        });
        $this->patch('/admin/members/'.$target->id, ['role' => 'owner', 'membership_status' => 'approved'])->assertForbidden();
        $this->assertTrue($lostRole);
        $this->assertSame(['member'], $target->fresh()->getRoleNames()->all());
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_failed_audit_rolls_back_member_role_and_status_changes(): void
    {
        $target = $this->member('researcher');
        Schema::drop('audit_entries');
        $this->withoutExceptionHandling();
        try {
            $this->patch('/admin/members/'.$target->id, ['role' => 'member', 'membership_status' => 'revoked']);
            $this->fail('Missing audit storage must roll back the update.');
        } catch (QueryException) {
            $this->assertSame(['researcher'], $target->fresh()->getRoleNames()->all());
            $this->assertSame('approved', $target->fresh()->membership_status);
        }
    }

    public function test_member_update_requires_authenticated_completed_security_enrollment(): void
    {
        $target = $this->member('member');
        $data = ['role' => 'researcher', 'membership_status' => 'approved'];
        $this->post('/logout');
        $this->patchJson('/admin/members/'.$target->id, $data)->assertUnauthorized();
        $unenrolled = User::factory()->create();
        $unenrolled->assignRole('owner');
        $this->actingAs($unenrolled)->withSession(['auth.password_confirmed_at' => time()]);
        $this->patchJson('/admin/members/'.$target->id, $data)->assertForbidden();
        $this->assertSame(['member'], $target->fresh()->getRoleNames()->all());
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_existing_delete_preserves_roles_audits_and_requests_durable_cancellation(): void
    {
        $target = $this->member('owner');
        $target->assignRole('researcher');
        $session = ResearchSession::create(['user_id' => $target->id, 'title' => 'Retained work']);
        $queued = ResearchRun::create(['user_id' => $target->id, 'research_session_id' => $session->id, 'idempotency_key' => 'delete-queued', 'prompt' => 'Private', 'status' => 'queued']);
        $running = ResearchRun::create(['user_id' => $target->id, 'research_session_id' => $session->id, 'idempotency_key' => 'delete-running', 'prompt' => 'Private', 'status' => 'running']);
        $this->delete('/admin/members/'.$target->id)->assertRedirect()->assertSessionHas('status', 'Membership revoked.');
        $this->delete('/admin/members/'.$target->id)->assertRedirect();
        $this->assertSame(['owner', 'researcher'], $target->fresh()->getRoleNames()->sort()->values()->all());
        $this->assertSame('revoked', $target->fresh()->membership_status);
        $this->assertSame('cancelled', $queued->fresh()->status);
        $this->assertSame('cancel_requested', $running->fresh()->status);
        $this->assertNull($running->fresh()->completed_at);
        $this->assertDatabaseCount('audit_entries', 1);
        $metadata = json_decode(DB::table('audit_entries')->first()->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($metadata['old']['roles'], $metadata['new']['roles']);
        $this->assertSame('approved', $metadata['old']['membership_status']);
        $this->assertSame('revoked', $metadata['new']['membership_status']);
        Http::assertNothingSent();
    }

    public function test_existing_delete_revalidates_actor_membership_after_owner_lock(): void
    {
        $target = $this->member('researcher');
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed) {
            if (! $changed && str_contains($query->sql, 'from "roles"') && in_array('owner', $query->bindings, true)) {
                $changed = true;
                DB::table('users')->where('id', $this->owner->id)->update(['membership_status' => 'revoked']);
            }
        });
        $this->delete('/admin/members/'.$target->id)->assertForbidden();
        $this->assertTrue($changed);
        $this->assertSame('approved', $target->fresh()->membership_status);
        $this->assertDatabaseCount('audit_entries', 0);
    }

    public function test_existing_delete_rejects_self_and_nonowner_without_membership_changes(): void
    {
        $this->delete('/admin/members/'.$this->owner->id)->assertForbidden();
        $target = $this->member('member');
        $this->login($target);
        $this->delete('/admin/members/'.$this->owner->id)->assertForbidden();
        $this->assertSame(1, User::role('owner')->where('membership_status', 'approved')->count());
        $this->assertSame('approved', $this->owner->fresh()->membership_status);
        $this->assertDatabaseCount('audit_entries', 0);
    }
}
