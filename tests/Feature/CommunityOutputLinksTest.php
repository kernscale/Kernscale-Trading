<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\CommunityTopic;
use App\Models\ResearchOutput;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommunityOutputLinksTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'member'): User
    {
        Role::findOrCreate($role);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function publishedOutput(User $author, string $status = 'published'): ResearchOutput
    {
        return ResearchOutput::create(['user_id' => $author->id, 'title' => 'Public title', 'kind' => 'strategy', 'content' => 'Evidence', 'status' => $status, 'original_checksum' => hash('sha256', 'Evidence'), 'version' => 3]);
    }

    public function test_discussion_links_exact_published_version_and_keeps_safe_metadata(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user('researcher');
        $member = $this->user();
        $output = $this->publishedOutput($author);
        $this->actingAs($member)->post('/community', ['title' => 'Discuss evidence', 'body' => 'My question', 'output_id' => $output->id, 'output_version' => '3', 'output_title' => 'Spoofed title'])->assertRedirect();
        $topic = CommunityTopic::firstOrFail();
        $this->assertSame($output->id, $topic->output_id);
        $this->assertSame(3, $topic->output_version);
        $this->assertSame('Public title', $topic->output_title);
        $this->assertSame('strategy', $topic->output_kind);
        $output->update(['title' => 'Later title', 'content' => 'Private later content', 'status' => 'draft', 'version' => 4]);
        $this->get('/community')->assertInertia(fn (Assert $page) => $page->component('hub/community')
            ->where('topics.0.output_id', $output->id)->where('topics.0.output_version', 3)
            ->where('topics.0.output_title', 'Public title')->where('topics.0.output_kind', 'strategy')
            ->missing('topics.0.output')->missing('topics.0.research_session'))
            ->assertDontSee('Private later content')->assertDontSee('Later title');
    }

    public function test_private_unpublished_missing_and_stale_output_links_are_rejected(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->user('researcher');
        $member = $this->user();
        $owner = $this->user('owner');
        foreach (['draft', 'in_review', 'changes_requested'] as $status) {
            $output = $this->publishedOutput($author, $status);
            foreach ([$author, $member, $owner] as $viewer) {
                $this->actingAs($viewer)->postJson('/community', ['title' => 'Question', 'body' => 'Question', 'output_id' => $output->id, 'output_version' => 3])->assertUnprocessable()->assertJsonValidationErrors('output_id')->assertDontSee('Evidence');
            }
        }
        $output = $this->publishedOutput($author);
        $this->actingAs($member)->postJson('/community', ['title' => 'Question', 'body' => 'Question', 'output_id' => $output->id, 'output_version' => 2])->assertUnprocessable()->assertJsonValidationErrors('output_id');
        $this->postJson('/community', ['title' => 'Question', 'body' => 'Question', 'output_id' => fake()->uuid(), 'output_version' => 3])->assertUnprocessable()->assertJsonValidationErrors('output_id');
        $this->postJson('/community', ['title' => 'Question', 'body' => 'Question', 'output_id' => $output->id])->assertUnprocessable()->assertJsonValidationErrors('output_version');
        $this->postJson('/community', ['title' => 'Question', 'body' => 'Question', 'output_version' => 3])->assertUnprocessable()->assertJsonValidationErrors('output_id');
        $this->assertDatabaseCount('community_topics', 0);
    }

    public function test_unlinked_discussions_comments_and_owner_locks_remain_compatible(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $member = $this->user();
        $owner = $this->user('owner');
        $this->actingAs($member)->post('/community?about=Public%20title', ['title' => 'Legacy question', 'body' => 'Body'])->assertRedirect();
        $topic = CommunityTopic::firstOrFail();
        $this->assertNull($topic->output_id);
        $this->assertNull($topic->output_version);
        $this->post('/community/'.$topic->id.'/comments', ['body' => 'Comment'])->assertRedirect();
        $this->patch('/community/'.$topic->id, ['locked' => true])->assertForbidden();
        $this->actingAs($owner)->patch('/community/'.$topic->id, ['locked' => true])->assertRedirect();
        $this->actingAs($member)->post('/community/'.$topic->id.'/comments', ['body' => 'Denied'])->assertForbidden();
        $this->actingAs($owner)->post('/community/'.$topic->id.'/comments', ['body' => 'Owner reply'])->assertRedirect();
        $this->patch('/community/'.$topic->id, ['locked' => false])->assertRedirect();
        $this->actingAs($member)->post('/community/'.$topic->id.'/comments', ['body' => 'Reopened'])->assertRedirect();
        $this->assertDatabaseCount('community_comments', 3);
    }
}
