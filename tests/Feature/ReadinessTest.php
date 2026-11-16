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
        $this->get('/ready')->assertOk()->assertJsonPath('research', 'unconfigured');
    }

    public function test_production_candidate_cannot_be_ready_with_plain_http_or_insecure_session_cookies(): void
    {
        $this->app->instance('env', 'production');
        config(['trading.release_commit' => str_repeat('a', 40), 'trading.release_image' => 'registry.invalid/hub@sha256:'.str_repeat('a', 64), 'mail.default' => 'smtp', 'app.url' => 'http://preview.kernscale.com', 'session.secure' => false]);
        $this->get('/ready')->assertStatus(503);
    }
}
