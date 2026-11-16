<?php

namespace Tests\Feature\Auth;

use App\Http\Responses\PasskeyLoginResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Route::middleware(['web', 'auth', 'approved', 'security.enrolled'])->get('/testing-enrollment', fn () => response('allowed'));
    }

    public function test_public_registration_is_closed(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_revoked_members_are_denied_even_with_an_existing_session(): void
    {
        $user = User::factory()->create(['membership_status' => 'revoked']);
        $this->actingAs($user)->get('/settings/profile')->assertForbidden();
        $this->assertGuest();
    }

    public function test_approved_members_can_reach_security_enrollment(): void
    {
        $user = User::factory()->create(['membership_status' => 'approved']);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->get('/settings/security')->assertOk();
    }

    public function test_existing_totp_enrollment_requires_session_assurance_before_settings(): void
    {
        $user = User::factory()->withTwoFactor()->create(['membership_status' => 'approved']);
        $this->actingAs($user)->get('/settings/profile')->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
    }

    public function test_required_totp_cannot_be_removed(): void
    {
        $user = User::factory()->withTwoFactor()->create(['membership_status' => 'approved']);
        $this->actingAs($user)->withSession(['security.totp_user' => $user->id, 'auth.password_confirmed_at' => time()])
            ->delete('/user/two-factor-authentication')->assertForbidden();
        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    public function test_last_passkey_cannot_be_removed(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $passkey = $user->passkeys()->create(['name' => 'key', 'credential_id' => 'key-id', 'credential' => []]);
        $this->actingAs($user)->withSession(['security.totp_user' => $user->id, 'auth.password_confirmed_at' => time()])
            ->delete('/user/passkeys/'.$passkey->id)->assertForbidden();
        $this->assertDatabaseCount('passkeys', 1);
    }

    public function test_recovery_code_establishes_assurance_and_is_single_use(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->withSession(['login.id' => $user->id, 'login.remember' => false])
            ->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])
            ->assertRedirect('/dashboard')->assertSessionHas('security.totp_user', $user->id);
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains('recovery-code-1', $user->fresh()->recoveryCodes());
    }

    public function test_passkey_login_response_requires_totp_before_access(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user);
        $request = Request::create('/passkeys/login', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(fn () => $user);
        $response = app(PasskeyLoginResponse::class)->toResponse($request);
        $this->assertSame(route('two-factor.login'), $response->getTargetUrl());
        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
        $this->assertNull(session('security.totp_user'));
    }

    public function test_pending_members_cannot_log_in(): void
    {
        $user = User::factory()->create(['membership_status' => 'pending']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_enrollment_requires_both_a_passkey_and_confirmed_totp(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/testing-enrollment')->assertRedirect('/settings/security');
        $user->passkeys()->create(['name' => 'key', 'credential_id' => 'enrollment-key', 'credential' => []]);
        $this->get('/testing-enrollment')->assertRedirect('/settings/security');
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->withSession(['security.totp_user' => $user->id])->get('/testing-enrollment')->assertOk();
    }

    public function test_unconfirmed_totp_does_not_grant_access(): void
    {
        $user = User::factory()->create(['two_factor_secret' => encrypt('secret')]);
        $user->passkeys()->create(['name' => 'key', 'credential_id' => 'unconfirmed-key', 'credential' => []]);
        $this->actingAs($user)->get('/testing-enrollment')->assertRedirect('/settings/security');
    }

    public function test_revocation_is_rechecked_against_database_on_an_existing_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/settings/profile')->assertOk();
        User::whereKey($user->id)->update(['membership_status' => 'revoked']);
        $this->get('/settings/profile')->assertForbidden();
        $this->assertGuest();
    }
}
