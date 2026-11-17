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
        $this->actingAs($owner)->post('/outputs/'.$output->id.'/review', ['decision' => 'publish', 'version' => 1])->assertRedirect();
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
        $this->assertDatabaseCount('output_revisions', 1);
    }

    public function test_validated_form_string_version_can_revise_the_current_draft(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user('researcher');
        $this->actingAs($author)->post('/outputs', ['title' => 'Thesis', 'kind' => 'research', 'content' => 'Original']);
        $output = ResearchOutput::firstOrFail();
        $this->patch('/outputs/'.$output->id, ['title' => 'Thesis', 'content' => 'Form revision', 'version' => '1'])->assertRedirect();
        $this->assertSame('Form revision', $output->fresh()->content);
        $this->assertSame(2, $output->fresh()->version);
    }

    public function test_stale_review_cannot_publish_a_new_revision_and_matching_review_can(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user('researcher');
        $owner = $this->user('owner');
        $this->actingAs($author)->post('/outputs', ['title' => 'Thesis', 'kind' => 'research', 'content' => 'Version one']);
        $output = ResearchOutput::firstOrFail();
        $this->post('/outputs/'.$output->id.'/submit')->assertRedirect();
        $this->actingAs($owner)->post('/outputs/'.$output->id.'/review', ['decision' => 'changes_requested', 'version' => 1])->assertRedirect();
        $this->actingAs($author)->patch('/outputs/'.$output->id, ['title' => 'Thesis', 'content' => 'Version two', 'version' => 1])->assertRedirect();
        $this->post('/outputs/'.$output->id.'/submit')->assertRedirect();
        $this->actingAs($owner)->post('/outputs/'.$output->id.'/review', ['decision' => 'publish', 'version' => 1])->assertConflict();
        $this->assertSame('in_review', $output->fresh()->status);
        $this->assertNull($output->fresh()->published_at);
        $this->assertDatabaseMissing('audit_entries', ['subject_id' => $output->id, 'action' => 'output.publish']);
        $this->post('/outputs/'.$output->id.'/review', ['decision' => 'publish', 'version' => '2'])->assertRedirect();
        $this->assertSame('published', $output->fresh()->status);
        $this->assertSame('Version two', $output->fresh()->content);
        $this->assertNotNull($output->fresh()->published_at);
    }

    public function test_downgraded_author_cannot_revise_or_submit_existing_drafts(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user('researcher');
        $this->actingAs($author)->post('/outputs', ['title' => 'Thesis', 'kind' => 'research', 'content' => 'Original']);
        $output = ResearchOutput::firstOrFail();
        $author->syncRoles(['member']);
        $this->patch('/outputs/'.$output->id, ['title' => 'Changed', 'content' => 'Unauthorized edit', 'version' => 1])->assertForbidden();
        $this->post('/outputs/'.$output->id.'/submit')->assertForbidden();
        $this->assertSame('Original', $output->fresh()->content);
        $this->assertSame('draft', $output->fresh()->status);
        $this->assertDatabaseCount('output_revisions', 0);
    }

    public function test_review_requires_the_version_that_was_actually_reviewed(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $owner = $this->user('owner');
        $this->actingAs($owner)->post('/outputs', ['title' => 'Thesis', 'kind' => 'research', 'content' => 'Original']);
        $output = ResearchOutput::firstOrFail();
        $this->post('/outputs/'.$output->id.'/submit');
        $this->postJson('/outputs/'.$output->id.'/review', ['decision' => 'publish'])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertSame('in_review', $output->fresh()->status);
    }
}
