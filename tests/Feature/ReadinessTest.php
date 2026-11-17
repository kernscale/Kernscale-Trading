<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_connection_alone_does_not_mean_release_schema_is_ready(): void
    {
        Schema::drop('watchlist_items');
        $this->get('/ready')->assertStatus(503)->assertJsonPath('status', 'unavailable');
    }

    public function test_migrated_local_app_reports_backend_configuration_honestly(): void
    {
        config(['session.driver' => 'database']);
        $this->get('/ready')->assertOk()->assertJsonPath('research', 'unconfigured')
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_unmigrated_database_sessions_do_not_prevent_an_unavailable_response(): void
    {
        config(['session.driver' => 'database']);
        Schema::drop('sessions');

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('status', 'unavailable');
    }

    public function test_production_candidate_cannot_be_ready_with_plain_http_or_insecure_session_cookies(): void
    {
        $this->app->instance('env', 'production');
        config(['trading.release_commit' => str_repeat('a', 40), 'trading.release_image' => 'registry.invalid/hub@sha256:'.str_repeat('a', 64), 'mail.default' => 'smtp', 'app.url' => 'http://preview.kernscale.com', 'session.secure' => false]);
        $this->get('/ready')->assertStatus(503);
    }

    private function productionCandidate(): void
    {
        $this->app->instance('env', 'production');
        config(['trading.release_commit' => str_repeat('a', 40),
            'trading.release_image' => 'registry.invalid/hub@sha256:'.str_repeat('a', 64),
            'app.url' => 'https://trading.kernscale.com', 'session.secure' => true,
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.kernscale.com',
            'mail.mailers.smtp.port' => 587, 'mail.from.address' => 'trading@kernscale.com']);
    }

    public function test_production_readiness_rejects_uninstalled_unsupported_and_malformed_transports(): void
    {
        $this->productionCandidate();
        config(['mail.default' => 'resend', 'services.resend.key' => 'synthetic-test-key']);
        $this->get('/ready')->assertStatus(503);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'unsupported-synthetic-driver']);
        $this->get('/ready')->assertStatus(503);
        config(['mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.scheme' => 'unsupported-synthetic-scheme']);
        $this->get('/ready')->assertStatus(503);
    }

    public function test_production_readiness_can_construct_configured_smtp_without_sending_mail(): void
    {
        $this->productionCandidate();
        $this->get('/ready')->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_production_readiness_validates_the_effective_mail_url_driver_and_host(): void
    {
        $this->productionCandidate();
        foreach (['smtp://smtp.kernscale.com:587?driver=log', 'smtp://smtp.kernscale.com:587?host=127.0.0.1'] as $url) {
            config(['mail.mailers.smtp.url' => $url]);
            $this->get('/ready')->assertStatus(503);
        }
    }
}
