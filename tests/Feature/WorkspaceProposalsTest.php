<?php

namespace Tests\Feature;

use App\Models\BoardCard;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use App\Models\WorkspaceProposal;
use App\Services\WorkspaceProposals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkspaceProposalsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ResearchSession $session;

    private ResearchRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Route::has('workspace.thesis')) {
            Route::middleware('web')->group(base_path('routes/workspace.php'));
        }
        $this->user = User::factory()->create();
        $this->user->assignRole('researcher');
        $this->session = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Private workspace']);
        $this->run = ResearchRun::create(['user_id' => $this->user->id, 'research_session_id' => $this->session->id, 'idempotency_key' => 'proposal-run', 'prompt' => 'Research', 'status' => 'running']);
    }

    private function propose(array $payload, int $id = 1): ?WorkspaceProposal
    {
        return app(WorkspaceProposals::class)->ingest($this->run, ['id' => $id, 'type' => 'workspace_proposal', 'payload' => $payload]);
    }

    public function test_proposals_require_human_application_and_duplicate_events_and_decisions_are_idempotent(): void
    {
        $payload = ['operation' => 'create_card', 'title' => 'Verify source', 'content' => 'Read evidence', 'status' => 'backlog'];
        $proposal = $this->propose($payload);
        $this->assertSame('pending', $proposal->status);
        $this->assertDatabaseCount('board_cards', 0);
        $this->assertSame($proposal->id, $this->propose($payload)->id);
        app(WorkspaceProposals::class)->apply($this->user, $proposal, 'apply');
        app(WorkspaceProposals::class)->apply($this->user, $proposal, 'apply');
        $this->assertDatabaseCount('workspace_proposals', 1);
        $this->assertDatabaseCount('board_cards', 1);
        $this->assertSame('applied', $proposal->fresh()->status);
        $this->assertSame('Verify source', $this->session->board()->firstOrFail()->title);
    }

    public function test_thesis_and_card_proposals_use_optimistic_versions(): void
    {
        $thesis = $this->propose(['operation' => 'update_thesis', 'content' => 'Evidence-backed thesis', 'expected_version' => 1]);
        app(WorkspaceProposals::class)->apply($this->user, $thesis, 'apply');
        $this->assertSame('Evidence-backed thesis', $this->session->fresh()->thesis);
        $this->assertSame(2, $this->session->fresh()->thesis_version);
        $card = BoardCard::create(['research_session_id' => $this->session->id, 'title' => 'Research evidence']);
        $proposal = $this->propose(['operation' => 'update_card', 'card_id' => $card->id, 'status' => 'review', 'expected_version' => 1], 2);
        app(WorkspaceProposals::class)->apply($this->user, $proposal, 'apply');
        $this->assertSame('review', $card->fresh()->status);
        $this->assertSame(2, $card->fresh()->version);
        $captured = app(WorkspaceProposals::class)->captureFor($this->session);
        $this->assertSame(['content' => 'Evidence-backed thesis', 'version' => 2], $captured['thesis']);
        $this->assertSame(2, $captured['board'][0]['version']);
    }

    public function test_stale_proposal_cannot_overwrite_human_edits(): void
    {
        $proposal = $this->propose(['operation' => 'update_thesis', 'content' => 'Old proposal', 'expected_version' => 1]);
        $this->session->update(['thesis' => 'Human revision', 'thesis_version' => 2]);
        try {
            app(WorkspaceProposals::class)->apply($this->user, $proposal, 'apply');
            $this->fail('Stale proposal must conflict');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('Human revision', $this->session->fresh()->thesis);
        $this->assertSame('pending', $proposal->fresh()->status);
    }

    public function test_owner_cannot_apply_another_researchers_private_proposal(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $proposal = $this->propose(['operation' => 'create_card', 'title' => 'Private card']);
        try {
            app(WorkspaceProposals::class)->apply($owner, $proposal, 'apply');
            $this->fail('Owner role must not bypass private workspace ownership');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertDatabaseCount('board_cards', 0);
    }

    public function test_malformed_and_cross_session_card_events_are_skipped(): void
    {
        $other = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Other private workspace']);
        $card = BoardCard::create(['research_session_id' => $other->id, 'title' => 'Other card']);
        foreach ([['operation' => 'delete_everything'], ['operation' => 'create_card', 'title' => 'Unsafe', 'user_id' => 999],
            ['operation' => 'update_thesis', 'content' => 'No version'],
            ['operation' => 'update_card', 'card_id' => $card->id, 'expected_version' => 1, 'status' => 'done']] as $payload) {
            $this->assertNull($this->propose($payload));
        }
        $this->assertDatabaseCount('workspace_proposals', 0);
    }

    public function test_rejected_proposals_do_not_change_workspace_and_cannot_later_apply(): void
    {
        $proposal = $this->propose(['operation' => 'create_card', 'title' => 'Discard']);
        app(WorkspaceProposals::class)->apply($this->user, $proposal, 'reject');
        app(WorkspaceProposals::class)->apply($this->user, $proposal, 'reject');
        $this->assertSame('rejected', $proposal->fresh()->status);
        $this->assertDatabaseCount('board_cards', 0);
        $this->expectException(HttpException::class);
        app(WorkspaceProposals::class)->apply($this->user, $proposal, 'apply');
    }

    public function test_changed_duplicate_event_does_not_replace_the_original_proposal(): void
    {
        $proposal = $this->propose(['operation' => 'create_card', 'title' => 'Original']);
        $replay = $this->propose(['operation' => 'create_card', 'title' => 'Changed event']);
        $this->assertSame($proposal->id, $replay->id);
        $this->assertSame('Original', $replay->payload['title']);
    }

    public function test_stale_card_proposal_and_revoked_membership_cannot_apply(): void
    {
        $card = BoardCard::create(['research_session_id' => $this->session->id, 'title' => 'Original']);
        $proposal = $this->propose(['operation' => 'update_card', 'card_id' => $card->id, 'expected_version' => 1, 'title' => 'Agent edit']);
        $card->update(['title' => 'Human edit', 'version' => 2]);
        try {
            app(WorkspaceProposals::class)->apply($this->user, $proposal, 'apply');
            $this->fail('Stale card proposal must conflict');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('Human edit', $card->fresh()->title);
        User::whereKey($this->user->id)->update(['membership_status' => 'revoked']);
        try {
            app(WorkspaceProposals::class)->apply($this->user, $proposal, 'reject');
            $this->fail('Revoked member must not decide proposals');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame('pending', $proposal->fresh()->status);
    }

    public function test_run_actor_must_match_the_private_session_owner(): void
    {
        $other = User::factory()->create();
        $this->run->update(['user_id' => $other->id]);
        $this->assertNull($this->propose(['operation' => 'create_card', 'title' => 'Forged actor']));
        $this->assertDatabaseCount('workspace_proposals', 0);
    }

    private function authenticateEnrolled(User $user): void
    {
        $user->forceFill(['two_factor_secret' => encrypt('synthetic-secret'), 'two_factor_confirmed_at' => now()])->save();
        $user->passkeys()->create(['name' => 'test key', 'credential_id' => 'workspace-key-'.$user->id, 'credential' => []]);
        $this->actingAs($user)->withSession(['security.totp_user' => $user->id]);
    }

    public function test_http_thesis_updates_clear_content_and_reject_stale_versions_and_private_context_access(): void
    {
        $this->authenticateEnrolled($this->user);
        $this->from('/research')->patch('/research/sessions/'.$this->session->id.'/thesis', ['content' => 'Human thesis', 'version' => 1])->assertRedirect('/research');
        $this->patch('/research/sessions/'.$this->session->id.'/thesis', ['content' => 'Stale edit', 'version' => 1])->assertConflict();
        $this->patch('/research/sessions/'.$this->session->id.'/thesis', ['content' => '', 'version' => 2])->assertRedirect();
        $this->getJson('/research/sessions/'.$this->session->id.'/workspace')->assertOk()->assertJsonPath('thesis.content', '')->assertJsonPath('thesis.version', 3);
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $this->authenticateEnrolled($owner);
        $this->getJson('/research/sessions/'.$this->session->id.'/workspace')->assertNotFound();
    }

    public function test_http_decision_requires_enrollment_then_human_apply_changes_workspace_once(): void
    {
        $proposal = $this->propose(['operation' => 'create_card', 'title' => 'Human approved card']);
        $this->actingAs($this->user)->postJson('/research/proposals/'.$proposal->id.'/decision', ['decision' => 'apply'])->assertForbidden();
        $this->assertDatabaseCount('board_cards', 0);
        $this->authenticateEnrolled($this->user);
        $this->post('/research/proposals/'.$proposal->id.'/decision', ['decision' => 'apply'])->assertRedirect();
        $this->post('/research/proposals/'.$proposal->id.'/decision', ['decision' => 'apply'])->assertRedirect();
        $this->assertDatabaseCount('board_cards', 1);
    }

    public function test_stale_inertia_edit_returns_to_the_workspace_with_an_error(): void
    {
        $this->authenticateEnrolled($this->user);
        $this->session->update(['thesis' => 'Current human revision', 'thesis_version' => 2]);

        $this->from('/research?session='.$this->session->id)
            ->withHeader('X-Inertia', 'true')
            ->patch('/research/sessions/'.$this->session->id.'/thesis', ['content' => 'Stale draft', 'version' => 1])
            ->assertStatus(303)
            ->assertRedirect('/research?session='.$this->session->id)
            ->assertSessionHasErrors('workspace');

        $this->assertSame('Current human revision', $this->session->fresh()->thesis);
    }
}
