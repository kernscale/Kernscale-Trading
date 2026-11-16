<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\ResearchSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResearchSessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_session_query_is_rejected_instead_of_becoming_a_collection(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        Role::findOrCreate('researcher');
        $user = User::factory()->create();
        $user->assignRole('researcher');
        $this->actingAs($user)->getJson('/research?session[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('session');
    }

    public function test_researcher_can_name_and_pin_only_their_own_session(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        Role::findOrCreate('researcher');
        $user = User::factory()->create();
        $user->assignRole('researcher');
        $other = User::factory()->create();
        $other->assignRole('researcher');
        $session = ResearchSession::create(['user_id' => $user->id, 'title' => 'Untitled research']);
        $this->actingAs($user)->patch('/research/sessions/'.$session->id, ['title' => 'Liquidity thesis', 'pinned' => true])->assertRedirect();
        $this->assertSame('Liquidity thesis', $session->fresh()->title);
        $this->assertTrue($session->fresh()->pinned);
        $this->actingAs($other)->patch('/research/sessions/'.$session->id, ['title' => 'Overwrite'])->assertNotFound();
        $this->assertSame('Liquidity thesis', $session->fresh()->title);
    }
}
