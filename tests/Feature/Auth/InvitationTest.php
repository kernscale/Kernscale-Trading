<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function invite(array $attributes = []): Invitation
    {
        return Invitation::create(array_merge([
            'email' => 'invited@example.com', 'token_hash' => hash('sha256', str_repeat('x', 64)),
            'role' => 'researcher', 'expires_at' => now()->addHour(),
        ], $attributes));
    }

    private function accept(string $email = 'invited@example.com')
    {
        return $this->post('/invitations/'.str_repeat('x', 64), [
            'name' => 'Invited', 'email' => $email, 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123',
        ]);
    }

    public function test_invitation_is_bound_to_email_and_cannot_be_replayed(): void
    {
        $invitation = $this->invite();
        $this->accept('other@example.com')->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        $this->accept()->assertRedirect('/settings/security');
        $user = User::firstOrFail();
        $this->assertSame('approved', $user->membership_status);
        $this->assertTrue($user->hasRole('researcher'));
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->post('/logout');
        $this->accept()->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_expired_invitation_cannot_create_an_account(): void
    {
        $this->invite(['expires_at' => now()->subMinute()]);
        $this->accept()->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_first_owner_bootstrap_sends_one_invitation_without_creating_users(): void
    {
        Mail::fake();
        $this->artisan('trading:bootstrap-owner', ['email' => 'owner@example.com'])->assertSuccessful();
        $this->artisan('trading:bootstrap-owner', ['email' => 'second@example.com'])->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('invitations', 1);
        $this->assertSame('owner', Invitation::firstOrFail()->role);
    }

    private function enrolled(string $role): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->assignRole($role);
        $user->passkeys()->create(['name' => 'key', 'credential_id' => 'key-'.$user->id, 'credential' => []]);

        return $user;
    }

    public function test_members_cannot_invite_owners_or_revoke_members(): void
    {
        $member = $this->enrolled('member');
        $target = User::factory()->create();
        $this->actingAs($member)->withSession(['security.totp_user' => $member->id, 'auth.password_confirmed_at' => time()]);
        $this->post('/admin/invitations', ['email' => 'new-owner@example.com', 'role' => 'owner'])->assertForbidden();
        $this->delete('/admin/members/'.$target->id)->assertForbidden();
        $this->assertSame('approved', $target->fresh()->membership_status);
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_owner_can_revoke_another_member_but_cannot_revoke_self(): void
    {
        $owner = $this->enrolled('owner');
        $target = $this->enrolled('researcher');
        $this->actingAs($owner)->withSession(['security.totp_user' => $owner->id, 'auth.password_confirmed_at' => time()]);
        $this->delete('/admin/members/'.$owner->id)->assertForbidden();
        $this->delete('/admin/members/'.$target->id)->assertRedirect();
        $this->assertSame('revoked', $target->fresh()->membership_status);
        $this->assertSame('approved', $owner->fresh()->membership_status);
    }
}
