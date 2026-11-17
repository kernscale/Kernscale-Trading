<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\ResearchAttachment;
use App\Models\ResearchMessage;
use App\Models\ResearchOutput;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResearchArtifactsTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private ResearchRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $this->author = User::factory()->create();
        $this->author->assignRole('researcher');
        $session = ResearchSession::create(['user_id' => $this->author->id, 'title' => 'Private research']);
        $this->run = ResearchRun::create(['user_id' => $this->author->id, 'research_session_id' => $session->id,
            'idempotency_key' => 'artifact-run', 'prompt' => 'PRIVATE_PROMPT', 'status' => 'completed']);
    }

    private function url(): string
    {
        return '/research/runs/'.$this->run->id.'/output';
    }

    private function canonical(string $text = 'Canonical research [1]'): ResearchMessage
    {
        return ResearchMessage::create(['research_run_id' => $this->run->id, 'research_session_id' => $this->run->research_session_id,
            'role' => 'assistant', 'content' => $text]);
    }

    public function test_saved_artifact_uses_only_canonical_text_and_actual_safe_evidence(): void
    {
        $message = $this->canonical();
        $payload = ['id' => 'e1', 'tool' => 'visit', 'args' => ['private' => 'PRIVATE_DEBUG'], 'inputs' => ['e2', 'invalid'],
            'payload' => ['pages' => [['url' => 'https://example.com/report', 'title' => 'Actual report', 'citation_index' => 1],
                ['url' => 'javascript:alert(1)'], ['url' => 'http://user:PRIVATE_KEY@example.com/'], ['url' => 'http://127.0.0.1/private']]],
            'output' => 'PRIVATE_DEBUG', 'provenance' => ['headers' => ['Authorization' => 'PRIVATE_KEY']]];
        $event = $this->run->events()->create(['remote_id' => 5, 'type' => 'record', 'payload' => $payload]);
        $this->run->events()->create(['remote_id' => 6, 'type' => 'token', 'payload' => ['text' => 'Incorrect partial text']]);
        $response = $this->actingAs($this->author)->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertCreated();
        $response->assertJsonPath('created', true)->assertJsonPath('output.content', $message->content);
        $output = ResearchOutput::findOrFail($response->json('output.id'));
        $this->assertSame($this->run->id, $output->run->id);
        $this->assertSame('draft', $output->status);
        $this->assertSame(hash('sha256', $message->content), $output->original_checksum);
        $this->assertSame($message->id, $output->provenance['assistant_message_id']);
        $this->assertSame($this->run->research_session_id, $output->provenance['session_id']);
        $this->assertSame($event->id, $output->provenance['evidence'][0]['event_id']);
        $this->assertSame(['e2'], $output->provenance['evidence'][0]['inputs']);
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $output->provenance['evidence'][0]['event_payload_sha256']);
        $this->assertCount(1, $output->sources);
        $this->assertSame('e1', $output->sources[0]['evidence_id']);
        $this->assertSame(1, $output->sources[0]['citation_index']);
        foreach (['PRIVATE_PROMPT', 'PRIVATE_DEBUG', 'PRIVATE_KEY', 'Incorrect partial text'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertDatabaseHas('audit_entries', ['subject_id' => $output->id, 'action' => 'output.created_from_run']);
    }

    public function test_repeated_save_is_idempotent_and_conflicting_creation_metadata_is_rejected(): void
    {
        $this->canonical();
        $this->actingAs($this->author);
        $id = $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertCreated()->json('output.id');
        $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertOk()->assertJsonPath('created', false)->assertJsonPath('output.id', $id);
        $this->postJson($this->url(), ['title' => 'Other', 'kind' => 'research'])->assertConflict();
        $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'strategy'])->assertConflict();
        $this->assertDatabaseCount('research_outputs', 1);
        $this->assertDatabaseCount('audit_entries', 1);
        $this->post($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertRedirect('/outputs?output='.$id);
    }

    public function test_foreign_researchers_and_owners_cannot_capture_private_run(): void
    {
        $this->canonical();
        foreach (['researcher', 'owner'] as $role) {
            $other = User::factory()->create();
            $other->assignRole($role);
            $this->actingAs($other)->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertNotFound();
        }
        $this->assertDatabaseCount('research_outputs', 0);
    }

    public function test_incomplete_and_missing_canonical_text_cannot_create_artifact(): void
    {
        $this->actingAs($this->author);
        $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertConflict();
        $message = $this->canonical(' ');
        $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertConflict();
        $message->update(['content' => 'Canonical']);
        foreach (['queued', 'running', 'collecting', 'cancel_requested', 'cancelled', 'failed', 'interrupted'] as $status) {
            $this->run->update(['status' => $status]);
            $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertConflict();
        }
        $this->assertDatabaseCount('research_outputs', 0);
    }

    public function test_draft_revisions_keep_original_provenance_and_replay_identity(): void
    {
        $this->canonical();
        $this->actingAs($this->author);
        $id = $this->postJson($this->url(), ['title' => 'Original', 'kind' => 'strategy'])->assertCreated()->json('output.id');
        $original = ResearchOutput::findOrFail($id);
        $this->patch('/outputs/'.$id, ['title' => 'Edited title', 'content' => 'Human revision', 'version' => 1,
            'provenance' => ['forged' => true], 'original_checksum' => 'forged'])->assertRedirect();
        $edited = $original->fresh();
        $this->assertSame(2, $edited->version);
        $this->assertSame($original->provenance, $edited->provenance);
        $this->assertSame($original->original_checksum, $edited->original_checksum);
        $this->assertDatabaseHas('output_revisions', ['research_output_id' => $id, 'version' => 1]);
        $this->postJson($this->url(), ['title' => 'Original', 'kind' => 'strategy'])->assertOk()->assertJsonPath('output.id', $id)->assertJsonPath('output.content', 'Human revision');
    }

    public function test_downgraded_members_and_client_claims_are_rejected(): void
    {
        $this->canonical();
        $this->actingAs($this->author);
        foreach (['content', 'sources', 'provenance', 'research_run_id'] as $claim) {
            $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research', $claim => 'client claim'])->assertUnprocessable();
        }
        $this->author->syncRoles(['member']);
        $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertForbidden();
        $this->assertDatabaseCount('research_outputs', 0);
    }

    public function test_original_membership_and_security_gates_remain_required(): void
    {
        $this->withMiddleware(EnsureSecurityEnrollment::class);
        $this->canonical();
        $this->actingAs($this->author)->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertForbidden();
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $this->author->forceFill(['membership_status' => 'suspended'])->save();
        $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertForbidden();
        $this->assertDatabaseCount('research_outputs', 0);
    }

    public function test_nullable_link_preserves_manual_and_native_outputs_and_private_visibility(): void
    {
        $this->canonical();
        $this->actingAs($this->author)->post('/outputs', ['title' => 'Manual', 'kind' => 'document', 'content' => 'Manual text'])->assertRedirect();
        $native = ResearchOutput::create(['user_id' => $this->author->id, 'external_id' => 'native-receipt', 'title' => 'Native report',
            'kind' => 'research', 'content' => 'Immutable native', 'original_checksum' => hash('sha256', 'Immutable native')]);
        $this->assertNull($native->research_run_id);
        $this->assertNull($native->provenance);
        $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertCreated();
        $this->assertDatabaseCount('research_outputs', 3);
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $this->assertSame(0, ResearchOutput::visibleTo($owner)->whereNotNull('research_run_id')->count());
        $this->patch('/outputs/'.$native->id, ['title' => 'Changed', 'content' => 'Changed', 'version' => 1])->assertConflict();
        $this->assertSame('Immutable native', $native->fresh()->content);
    }

    public function test_attachment_admission_and_actual_reads_are_distinct_hashed_and_immutable(): void
    {
        $this->canonical();
        $attachment = ['id' => (string) Str::uuid(), 'name' => 'Read report.pdf', 'media_type' => 'application/pdf',
            'size' => 123, 'sha256' => hash('sha256', 'synthetic-original'), 'text' => 'PRIVATE_ATTACHMENT_TEXT',
            'storage_key' => 'PRIVATE_STORAGE_PATH'];
        $unread = [...$attachment, 'id' => (string) Str::uuid(), 'name' => 'Unread report.pdf'];
        $this->run->update(['attachments' => [$attachment, $unread]]);
        $payload = ['id' => $attachment['id'], 'sha256' => $attachment['sha256'], 'name' => 'PRIVATE_EVENT_NAME',
            'text' => 'PRIVATE_EVENT_TEXT', 'storage_key' => 'PRIVATE_EVENT_PATH'];
        $event = $this->run->events()->create(['remote_id' => 1, 'type' => 'attachment_reference', 'payload' => $payload]);
        foreach ([[...$payload, 'sha256' => hash('sha256', 'wrong')], [...$payload, 'id' => (string) Str::uuid()],
            [...$payload, 'extracted_text_sha256' => hash('sha256', 'wrong')], ['sha256' => $attachment['sha256']]] as $index => $forged) {
            $this->run->events()->create(['remote_id' => $index + 2, 'type' => 'attachment_reference', 'payload' => $forged]);
        }
        $response = $this->actingAs($this->author)->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertCreated();
        $output = ResearchOutput::findOrFail($response->json('output.id'));
        $admitted = $output->provenance['admitted_attachments'];
        $used = $output->provenance['attachment_references'];
        $this->assertCount(2, $admitted);
        $this->assertCount(1, $used);
        $this->assertSame(['id' => $attachment['id'], 'name' => 'Read report.pdf', 'media_type' => 'application/pdf',
            'size' => 123, 'sha256' => $attachment['sha256'], 'extracted_text_sha256' => hash('sha256', $attachment['text'])], $admitted[0]);
        $this->assertSame($attachment['id'], $used[0]['id']);
        $this->assertSame($event->id, $used[0]['event_id']);
        $this->assertSame(1, $used[0]['remote_event_id']);
        $this->assertSame(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $used[0]['event_payload_sha256']);
        $this->assertSame([], $output->sources);
        foreach (['PRIVATE_ATTACHMENT_TEXT', 'PRIVATE_STORAGE_PATH', 'PRIVATE_EVENT_NAME', 'PRIVATE_EVENT_TEXT', 'PRIVATE_EVENT_PATH', 'PRIVATE_PROMPT'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->patch('/outputs/'.$output->id, ['title' => 'Edited', 'content' => 'Revision', 'version' => 1])->assertRedirect();
        $this->run->update(['attachments' => []]);
        $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertOk()->assertJsonPath('output.id', $output->id);
        $this->assertSame($output->provenance, $output->fresh()->provenance);
    }

    public function test_publishing_attachment_provenance_does_not_grant_private_download_access(): void
    {
        $this->canonical();
        $attachment = ResearchAttachment::create(['research_session_id' => $this->run->research_session_id, 'user_id' => $this->author->id,
            'name' => 'Private.txt', 'media_type' => 'text/plain', 'size' => 7, 'sha256' => hash('sha256', 'Private'), 'text' => 'Private', 'storage_key' => 'not-public']);
        $this->run->update(['attachments' => [[...$attachment->receipt(), 'text' => 'Private']]]);
        $id = $this->actingAs($this->author)->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertCreated()->json('output.id');
        $this->post('/outputs/'.$id.'/submit')->assertRedirect();
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $this->actingAs($owner)->post('/outputs/'.$id.'/review', ['decision' => 'publish', 'version' => 1])->assertRedirect();
        $this->assertTrue(ResearchOutput::visibleTo($owner)->whereKey($id)->exists());
        $this->getJson('/research/sessions/'.$this->run->research_session_id.'/attachments/'.$attachment->id)->assertNotFound();
        $this->postJson($this->url(), ['title' => 'Report', 'kind' => 'research'])->assertNotFound();
    }
}
