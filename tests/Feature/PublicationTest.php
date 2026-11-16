<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\ResearchOutput;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PublicationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        Role::findOrCreate($role);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_private_draft_is_invisible_to_other_researchers_and_owner_until_submitted(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user('researcher');
        $owner = $this->user('owner');
        $this->actingAs($author)->post('/outputs', ['title' => 'Private thesis', 'kind' => 'strategy', 'content' => 'Evidence'])->assertRedirect();
        $output = ResearchOutput::firstOrFail();
        $this->assertSame(0, ResearchOutput::visibleTo($owner)->count());
        $this->actingAs($author)->post('/outputs/'.$output->id.'/submit')->assertRedirect();
        $this->assertSame(1, ResearchOutput::visibleTo($owner)->count());
        $member = $this->user('member');
        $this->assertSame(0, ResearchOutput::visibleTo($member)->count());
        $this->actingAs($member)->post('/outputs/'.$output->id.'/review', ['decision' => 'publish'])->assertForbidden();
        $this->actingAs($owner)->post('/outputs/'.$output->id.'/review', ['decision' => 'publish'])->assertRedirect();
        $this->assertSame(1, ResearchOutput::visibleTo($member)->count());
        $this->actingAs($author)->patch('/outputs/'.$output->id, ['title' => 'Changed', 'content' => 'Changed', 'version' => 1])->assertStatus(409);
    }

    public function test_stale_edits_cannot_overwrite_a_newer_draft_revision(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user('researcher');
        $this->actingAs($author)->post('/outputs', ['title' => 'Thesis', 'kind' => 'research', 'content' => 'Original'])->assertRedirect();
        $output = ResearchOutput::firstOrFail();
        $this->patch('/outputs/'.$output->id, ['title' => 'Thesis', 'content' => 'Revision', 'version' => 1])->assertRedirect();
        $this->patch('/outputs/'.$output->id, ['title' => 'Thesis', 'content' => 'Stale', 'version' => 1])->assertStatus(409);
        $this->assertSame('Revision', $output->fresh()->content);
        $this->assertDatabaseCount('output_revisions',1);
    }
}
