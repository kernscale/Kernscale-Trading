<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\ResearchOutput;
use App\Models\ResearchSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DerivedOutputsTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private ResearchOutput $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $this->author = User::factory()->create();
        $this->author->assignRole('researcher');
        $this->source = ResearchOutput::create(['user_id' => $this->author->id, 'title' => 'Native receipt',
            'external_id' => 'native-original', 'kind' => 'research', 'status' => 'changes_requested',
            'content' => 'Immutable scheduled research', 'sources' => [['url' => 'https://example.com', 'title' => 'Actual source']],
            'original_checksum' => hash('sha256', 'Immutable scheduled research')]);
        $this->actingAs($this->author);
    }

    private function url(?ResearchOutput $source = null): string
    {
        return '/outputs/'.($source ?? $this->source)->id.'/draft';
    }

    private function input(array $changes = []): array
    {
        return [...['title' => 'Human working draft', 'kind' => 'strategy', 'version' => 1, 'idempotency_key' => 'derive-key'], ...$changes];
    }

    public function test_native_original_stays_sealed_and_derived_draft_can_use_existing_revision_flow(): void
    {
        $before = $this->source->fresh()->getAttributes();
        $this->patch('/outputs/'.$this->source->id, ['title' => 'Changed', 'content' => 'Changed', 'version' => 1])->assertConflict();
        $response = $this->postJson($this->url(), $this->input())->assertCreated()->assertJsonPath('created', true);
        $draft = ResearchOutput::findOrFail($response->json('output.id'));
        $this->assertSame($this->source->id, $draft->source_output_id);
        $this->assertSame(1, $draft->source_output_version);
        $this->assertNull($draft->external_id);
        $this->assertNull($draft->research_run_id);
        $this->assertNull($draft->research_session_id);
        $this->assertSame('draft', $draft->status);
        $this->assertSame($this->source->content, $draft->content);
        $this->assertSame($this->source->sources, $draft->sources);
        $this->assertSame($this->source->original_checksum, $draft->provenance['source_original_checksum']);
        $this->assertSame(hash('sha256', $this->source->content), $draft->provenance['source_content_sha256']);
        $this->patch('/outputs/'.$draft->id, ['title' => 'Human revision', 'content' => 'Reviewed improvements', 'version' => 1])->assertRedirect();
        $this->assertSame('Reviewed improvements', $draft->fresh()->content);
        $this->assertSame($draft->provenance, $draft->fresh()->provenance);
        $this->assertSame($before, $this->source->fresh()->getAttributes());
    }

    public function test_replay_keeps_same_draft_after_revision_and_conflicting_creation_metadata_is_rejected(): void
    {
        $id = $this->postJson($this->url(), $this->input())->assertCreated()->json('output.id');
        $this->patch('/outputs/'.$id, ['title' => 'Edited', 'content' => 'Human revision', 'version' => 1])->assertRedirect();
        $this->source->update(['version' => 2]);
        $this->postJson($this->url(), $this->input())->assertOk()->assertJsonPath('created', false)->assertJsonPath('output.id', $id)->assertJsonPath('output.content', 'Human revision');
        $this->postJson($this->url(), $this->input(['idempotency_key' => 'new-key']))->assertConflict();
        foreach ([['title' => 'Other'], ['kind' => 'document'], ['version' => 2]] as $change) {
            $this->postJson($this->url(), $this->input($change))->assertConflict();
        }
        $other = $this->source->replicate(['external_id']);
        $other->save();
        $this->postJson($this->url($other), $this->input(['version' => 2]))->assertConflict();
        $this->assertDatabaseCount('research_outputs', 3);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'output.derived')->count());
        $this->post($this->url(), $this->input())->assertRedirect('/outputs?output='.$id);
    }

    public function test_existing_visible_policy_preserves_private_owner_review_boundary(): void
    {
        $foreign = User::factory()->create();
        $foreign->assignRole('researcher');
        $privateSession = ResearchSession::create(['user_id' => $foreign->id, 'title' => 'Private session']);
        $this->source->update(['user_id' => $foreign->id, 'external_id' => null, 'status' => 'draft', 'research_session_id' => $privateSession->id,
            'provenance' => ['session_id' => $privateSession->id, 'storage_path' => 'PRIVATE_FILE_PATH', 'admitted_attachments' => [['name' => 'PRIVATE_FILE_NAME']]]]);
        $this->postJson($this->url(), $this->input())->assertNotFound();
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $this->actingAs($owner)->postJson($this->url(), $this->input())->assertNotFound();
        $this->source->update(['status' => 'in_review']);
        $this->actingAs($this->author)->postJson($this->url(), $this->input())->assertNotFound();
        $response = $this->actingAs($owner)->postJson($this->url(), $this->input())->assertCreated();
        $response->assertJsonPath('output.research_session_id', null)->assertJsonPath('output.provenance.inherited_source_references', []);
        foreach ([$privateSession->id, 'PRIVATE_FILE_PATH', 'PRIVATE_FILE_NAME'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }

    public function test_published_source_is_derivable_but_each_users_key_and_new_draft_remain_private(): void
    {
        $this->source->update(['status' => 'published']);
        $first = $this->postJson($this->url(), $this->input())->assertCreated()->json('output.id');
        $other = User::factory()->create();
        $other->assignRole('researcher');
        $second = $this->actingAs($other)->postJson($this->url(), $this->input())->assertCreated()->json('output.id');
        $this->assertNotSame($first, $second);
        $this->assertFalse(ResearchOutput::visibleTo($other)->whereKey($first)->exists());
        $this->assertSame($other->id, ResearchOutput::findOrFail($second)->user_id);
        $other->syncRoles(['member']);
        $this->postJson($this->url(), $this->input(['idempotency_key' => 'another']))->assertForbidden();
    }

    public function test_target_session_must_be_explicit_owned_and_is_part_of_replay_metadata(): void
    {
        $own = ResearchSession::create(['user_id' => $this->author->id, 'title' => 'Own target']);
        $foreign = ResearchSession::create(['user_id' => User::factory()->create()->id, 'title' => 'Other target']);
        $this->postJson($this->url(), $this->input(['session_id' => $foreign->id]))->assertNotFound();
        $this->postJson($this->url(), $this->input(['session_id' => $own->id]))->assertCreated()->assertJsonPath('output.research_session_id', $own->id);
        $this->postJson($this->url(), $this->input())->assertConflict();
    }

    public function test_own_reference_whitelist_and_server_copy_reject_client_provenance_claims(): void
    {
        $recordHash = hash('sha256', 'actual saved record');
        $this->source->update(['provenance' => ['evidence' => [['evidence_id' => 'e1', 'event_id' => 5, 'remote_event_id' => 8,
            'event_payload_sha256' => $recordHash, 'inputs' => ['e2'], 'args' => ['private' => 'PRIVATE_DEBUG']]], 'storage_path' => 'PRIVATE_FILE']]);
        foreach (['content', 'sources', 'provenance'] as $claim) {
            $this->postJson($this->url(), $this->input([$claim => 'untrusted']))->assertUnprocessable();
        }
        $this->postJson($this->url(), $this->input(['version' => 99]))->assertConflict();
        $response = $this->postJson($this->url(), $this->input())->assertCreated();
        $response->assertJsonPath('output.provenance.inherited_source_references.evidence.0.event_payload_sha256', $recordHash);
        $this->assertStringNotContainsString('PRIVATE_DEBUG', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE_FILE', $response->getContent());
    }
}
