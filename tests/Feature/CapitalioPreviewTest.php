<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CapitalioPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_design_is_available_only_to_a_local_owner(): void
    {
        $this->app->instance('env', 'local');
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $this->actingAs($owner)->get('/design/capitalio')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('hub/capitalio-preview', false)
                ->where('designPreview', true));
    }

    public function test_sample_design_is_unavailable_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $owner = User::factory()->create();
        $owner->assignRole('owner');

        $this->actingAs($owner)->get('/design/capitalio')->assertNotFound();
    }

    public function test_sample_design_is_not_available_to_other_members(): void
    {
        $this->app->instance('env', 'local');
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);

        $this->actingAs(User::factory()->create())->get('/design/capitalio')->assertForbidden();
    }
}
