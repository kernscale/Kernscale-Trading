<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Horizon\Horizon;
use Tests\TestCase;

class OperationalSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_invitations_reject_log_array_unknown_and_logging_failover_before_creation(): void
    {
        Mail::fake();
        $this->app['env'] = 'production';
        foreach (['log', 'array', 'missing', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            try {
                Invitation::issue('invited@example.org', 'member');
                $this->fail('Unsafe production invitation delivery should be rejected');
            } catch (\RuntimeException $error) {
                $this->assertDatabaseCount('invitations', 0);
            }
        }
    }

    public function test_production_invitations_reject_default_smtp_but_allow_explicit_real_transport_without_sending(): void
    {
        Mail::fake();
        $this->app['env'] = 'production';
        config(['mail.default' => 'smtp']);
        try {
            Invitation::issue('invited@example.org', 'member');
            $this->fail('Default localhost SMTP should be rejected');
        } catch (\RuntimeException $error) {
            $this->assertDatabaseCount('invitations', 0);
        }
        config(['mail.mailers.smtp.host' => 'smtp.kernscale.com', 'mail.mailers.smtp.port' => 587, 'mail.from.address' => 'trading@kernscale.com']);
        Invitation::issue('invited@example.org', 'member');
        $this->assertDatabaseCount('invitations', 1);
    }

    public function test_horizon_rejects_guest_member_revoked_owner_and_unenrolled_owner_even_locally(): void
    {
        $this->app['env'] = 'local';
        $request = Request::create('/horizon');
        $request->setLaravelSession($this->app['session.store']);
        $this->assertFalse((bool) Horizon::check($request));
        $this->get('/horizon')->assertForbidden();
        $user = User::factory()->create();
        $request->setUserResolver(fn () => $user);
        $this->assertFalse((bool) Horizon::check($request));
        $user->assignRole('owner');
        $this->assertFalse((bool) Horizon::check($request));
        $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $user->passkeys()->create(['name' => 'key', 'credential_id' => 'horizon-key', 'credential' => []]);
        $this->assertFalse((bool) Horizon::check($request));
        $request->session()->put('security.totp_user', $user->id);
        $this->assertTrue((bool) Horizon::check($request));
        User::whereKey($user->id)->update(['membership_status' => 'revoked']);
        $this->assertFalse((bool) Horizon::check($request));
        $user->forceFill(['membership_status' => 'approved'])->save();
        $user->removeRole('owner');
        $this->assertFalse((bool) Horizon::check($request));
    }

    public function test_confirmation_event_preserves_assurance_on_subsequent_full_navigation(): void
    {
        $user = User::factory()->create(['two_factor_secret' => encrypt('synthetic-secret')]);
        $this->mock(TwoFactorAuthenticationProvider::class)
            ->shouldReceive('verify')->once()->andReturnTrue();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/user/confirmed-two-factor-authentication', ['code' => '123456'])
            ->assertRedirect()->assertSessionHas('security.totp_user', $user->id);
        $this->get('/settings/profile')->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
