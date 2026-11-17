<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\ResearchOutput;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OutputHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'researcher'): User
    {
        Role::findOrCreate($role);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_history_returns_only_genuine_prior_snapshots_and_current_version(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user();
        $this->actingAs($author)->post('/outputs', ['title' => 'Original title', 'kind' => 'research', 'content' => 'Original evidence', 'sources' => [['url' => 'https://example.com/source']]])->assertRedirect();
        $output = ResearchOutput::firstOrFail();
        $this->getJson('/outputs/'.$output->id.'/revisions')->assertOk()->assertExactJson(['output_id' => $output->id, 'current_version' => 1, 'revisions' => []]);
        $this->patch('/outputs/'.$output->id, ['title' => 'Second title', 'content' => 'Second evidence', 'version' => 1])->assertRedirect();
        $this->patch('/outputs/'.$output->id, ['title' => 'Current title', 'content' => 'Current evidence', 'version' => 2])->assertRedirect();

        $this->getJson('/outputs/'.$output->id.'/revisions')->assertOk()
            ->assertJsonPath('current_version', 3)->assertJsonCount(2, 'revisions')
            ->assertJsonPath('revisions.0.version', 2)->assertJsonPath('revisions.0.snapshot.title', 'Second title')
            ->assertJsonPath('revisions.0.snapshot.content', 'Second evidence')
            ->assertJsonPath('revisions.1.version', 1)->assertJsonPath('revisions.1.snapshot.content', 'Original evidence')
            ->assertJsonPath('revisions.1.snapshot.sources.0.url', 'https://example.com/source');

        Role::findOrCreate('member');
        $author->syncRoles(['member']);
        $this->getJson('/outputs/'.$output->id.'/revisions')->assertOk();
        $this->patch('/outputs/'.$output->id, ['title' => 'Denied', 'content' => 'Denied', 'version' => 3])->assertForbidden();
    }

    public function test_visible_current_outputs_never_grant_other_users_private_history(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user();
        $owner = $this->user('owner');
        $member = $this->user('member');
        $other = $this->user();
        $this->actingAs($author)->post('/outputs', ['title' => 'Thesis', 'kind' => 'research', 'content' => 'Private early evidence']);
        $output = ResearchOutput::firstOrFail();
        $this->patch('/outputs/'.$output->id, ['title' => 'Final', 'content' => 'Public evidence', 'version' => 1]);

        foreach (['draft', 'in_review', 'published'] as $status) {
            $output->update(['status' => $status]);
            foreach ([$owner, $member, $other] as $viewer) {
                $this->actingAs($viewer)->getJson('/outputs/'.$output->id.'/revisions')->assertNotFound()->assertDontSee('Private early evidence');
            }
        }
    }

    public function test_native_output_has_no_manufactured_revision_history(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user('owner');
        $output = ResearchOutput::create(['user_id' => $author->id, 'title' => 'Native report', 'kind' => 'research', 'content' => 'Imported report', 'original_checksum' => hash('sha256', 'Imported report'), 'external_id' => 'native:run-1', 'version' => 7]);
        $this->actingAs($author)->getJson('/outputs/'.$output->id.'/revisions')->assertOk()
            ->assertJsonPath('current_version', 7)->assertJsonCount(0, 'revisions');
    }
}
