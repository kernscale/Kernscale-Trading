<?php

namespace Tests\Feature;

use App\Jobs\DispatchResearch;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use App\Services\ResearchCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ResearchDispatchStateTest extends TestCase
{
    use RefreshDatabase;

    private ResearchRun $run;

    private ResearchSession $session;

    private User $user;

    private ResearchCoordinator $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['trading.bridge_url' => 'http://bridge.test', 'trading.bridge_token' => str_repeat('x', 40)]);
        $this->user = User::factory()->create();
        $this->user->assignRole('researcher');
        $this->session = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Dispatch state']);
        $this->service = app(ResearchCoordinator::class);
        $this->run = $this->service->start($this->user, $this->session, 'Research evidence', 'dispatch-state');
    }

    public function test_accepted_upstream_queued_run_is_immediately_pollable_as_waiting(): void
    {
        Http::fake([
            'bridge.test/v1/researchers/*/runs/*/events*' => Http::sequence()->push(['status' => 'running', 'events' => []])->push(['status' => 'completed', 'events' => [['id' => 1, 'type' => 'completed', 'payload' => ['text' => 'Complete result']]]]),
            'bridge.test/v1/researchers/*/runs/*' => Http::response(['status' => 'queued']),
        ]);
        $this->service->dispatch($this->run);
        $this->assertSame('waiting', $this->run->fresh()->status);
        $this->service->sync($this->run);
        $this->assertSame('running', $this->run->fresh()->status);
        $this->service->sync($this->run);
        $this->assertSame('completed', $this->run->fresh()->status);
        $this->assertSame('Complete result', $this->session->messages()->where('role', 'assistant')->firstOrFail()->content);
        Http::assertSentCount(3);
    }

    public function test_waiting_cancellation_requests_upstream_confirmation(): void
    {
        $this->run->update(['status' => 'waiting']);
        Http::fake(['bridge.test/v1/researchers/*/runs/*/cancel' => Http::response(['status' => 'cancel_requested'])]);
        $this->service->cancel($this->run);
        $this->assertSame('cancel_requested', $this->run->fresh()->status);
        $this->assertNull($this->run->fresh()->completed_at);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/cancel'));
    }

    public function test_stale_queued_object_after_database_claim_cancels_upstream_instead_of_confirming_locally(): void
    {
        ResearchRun::whereKey($this->run->id)->update(['status' => 'dispatching']);
        Http::fake(['bridge.test/v1/researchers/*/runs/*/cancel' => Http::response(['status' => 'cancel_requested'])]);
        $this->service->cancel($this->run);
        $this->assertSame('cancel_requested', $this->run->fresh()->status);
        $this->assertNull($this->run->fresh()->completed_at);
        Http::assertSentCount(1);
    }

    public function test_confirmed_cancelled_runs_are_never_dispatched_or_overwritten_by_stale_cancel(): void
    {
        ResearchRun::whereKey($this->run->id)->update(['status' => 'cancelled', 'completed_at' => now()]);
        $this->service->dispatch($this->run);
        $this->service->cancel($this->run);
        $this->assertSame('cancelled', $this->run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_waiting_turn_blocks_a_second_session_turn(): void
    {
        $this->run->update(['status' => 'waiting']);
        try {
            $this->service->start($this->user, $this->session, 'Second prompt', 'second-key');
            $this->fail('Accepted waiting turn must remain active');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('research_runs', 1);
    }

    public function test_late_upstream_queued_response_preserves_pending_cancellation(): void
    {
        $this->run->update(['status' => 'cancel_requested']);
        $this->service->ingest($this->run, ['status' => 'queued']);
        $this->assertSame('cancel_requested', $this->run->fresh()->status);
    }

    public function test_dispatch_claim_does_not_overwrite_a_cancellation_that_won_after_its_read(): void
    {
        $injected = false;
        $identity = $this->run->id;
        ResearchRun::retrieved(function (ResearchRun $run) use (&$injected, $identity) {
            if (! $injected && $run->id === $identity) {
                $injected = true;
                ResearchRun::whereKey($identity)->update(['status' => 'cancel_requested']);
            }
        });
        $this->service->dispatch($this->run);
        $this->assertTrue($injected);
        $this->assertSame('cancel_requested', $this->run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_queued_cancel_does_not_overwrite_a_confirmed_completion(): void
    {
        ResearchRun::whereKey($this->run->id)->update(['status' => 'completed', 'completed_at' => now()]);
        $this->service->cancel($this->run);
        $this->assertSame('completed', $this->run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_reconciliation_polls_accepted_waiting_without_redispatching(): void
    {
        $this->run->update(['status' => 'waiting']);
        Http::fake(['bridge.test/v1/researchers/*/runs/*/events*' => Http::response(['status' => 'running', 'events' => []])]);
        $this->artisan('trading:reconcile')->assertSuccessful();
        $this->assertSame('running', $this->run->fresh()->status);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Queue::assertPushed(DispatchResearch::class, 1);
    }

    public function test_cancellation_during_put_is_preserved_and_sent_upstream_after_acceptance(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'PUT') {
                ResearchRun::whereKey($this->run->id)->update(['status' => 'cancel_requested']);

                return Http::response(['status' => 'queued']);
            }

            return Http::response(['status' => 'cancel_requested']);
        });
        $this->service->dispatch($this->run);
        $this->assertSame('cancel_requested', $this->run->fresh()->status);
        $this->assertNull($this->run->fresh()->completed_at);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/cancel'));
    }

    public function test_durable_pre_admission_cancel_receipt_unblocks_session_without_a_prompt_put(): void
    {
        ResearchRun::whereKey($this->run->id)->update(['status' => 'dispatching']);
        Http::fake(['bridge.test/v1/researchers/*/runs/*/cancel' => Http::response(['id' => $this->run->id, 'status' => 'cancelled', 'events' => []])]);
        $this->service->cancel($this->run);
        $this->service->dispatch($this->run);
        $this->artisan('trading:reconcile')->assertSuccessful();
        $this->assertSame('cancelled', $this->run->fresh()->status);
        $this->assertNotNull($this->run->fresh()->completed_at);
        $next = $this->service->start($this->user, $this->session, 'Next authorized turn', 'next-after-cancellation');
        $this->assertSame('queued', $next->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
        Http::assertSentCount(1);
    }

    public function test_missing_or_unavailable_cancellation_response_never_confirms_cancellation(): void
    {
        ResearchRun::whereKey($this->run->id)->update(['status' => 'dispatching']);
        Http::fake(['bridge.test/v1/researchers/*/runs/*/cancel' => Http::sequence()->push(['detail' => 'run not found'], 404)->push(['detail' => 'unavailable'], 503)]);
        foreach ([404, 503] as $status) {
            try {
                $this->service->cancel($this->run);
                $this->fail('A failed cancellation must not be confirmed.');
            } catch (RequestException $error) {
                $this->assertSame($status, $error->response->status());
            }
            $this->assertSame('cancel_requested', $this->run->fresh()->status);
            $this->assertNull($this->run->fresh()->completed_at);
        }
        $this->service->dispatch($this->run);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_timed_out_put_preserves_durable_claim_and_does_not_replay_prompt_on_cancel(): void
    {
        $puts = 0;
        $transactionLevel = DB::transactionLevel();
        Http::fake(function ($request) use (&$puts, $transactionLevel) {
            if ($request->method() === 'PUT') {
                $puts++;
                // A transaction spanning admission could roll the claim back after process death.
                $this->assertSame($transactionLevel, DB::transactionLevel());
                $this->assertSame('dispatching', $this->run->fresh()->status);
                throw new ConnectionException('Synthetic timeout after an ambiguously accepted PUT');
            }

            return Http::response(['detail' => 'run not found'], 404);
        });
        try {
            $this->service->dispatch($this->run);
            $this->fail('Ambiguous dispatch should report the connection failure.');
        } catch (ConnectionException) {
            $this->assertSame('dispatching', $this->run->fresh()->status);
        }
        try {
            $this->service->cancel($this->run);
            $this->fail('Missing cancellation acknowledgement should remain pending.');
        } catch (RequestException) {
            $this->assertSame('cancel_requested', $this->run->fresh()->status);
        }
        $this->service->dispatch($this->run);
        (new DispatchResearch($this->run->id))->failed(null);
        $this->assertSame('cancel_requested', $this->run->fresh()->status);
        $this->assertNull($this->run->fresh()->completed_at);
        $this->assertSame(1, $puts);
    }

    public function test_cancel_completed_metadata_drains_final_events_before_sealing_assistant_text(): void
    {
        $this->run->update(['status' => 'running']);
        $this->run->events()->create(['remote_id' => 1, 'type' => 'token', 'payload' => ['text' => 'Partial']]);
        Http::fake(['bridge.test/*' => Http::sequence()->push(['status' => 'completed'])->push(['status' => 'completed', 'events' => [['id' => 2, 'type' => 'completed', 'payload' => ['text' => 'Canonical final']]]])]);
        $this->service->cancel($this->run);
        $this->assertSame('collecting', $this->run->fresh()->status);
        $this->assertNull($this->run->fresh()->completed_at);
        $this->assertDatabaseMissing('research_messages', ['research_run_id' => $this->run->id, 'role' => 'assistant']);
        $this->service->sync($this->run);
        $this->assertSame('completed', $this->run->fresh()->status);
        $this->assertDatabaseHas('research_messages', ['research_run_id' => $this->run->id, 'role' => 'assistant', 'content' => 'Canonical final']);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_dispatch_replay_completed_metadata_drains_final_events_before_sealing_assistant_text(): void
    {
        $this->run->update(['status' => 'dispatching']);
        $this->run->events()->create(['remote_id' => 1, 'type' => 'token', 'payload' => ['text' => 'Partial']]);
        Http::fake(['bridge.test/*' => Http::sequence()->push(['status' => 'completed'])->push(['status' => 'completed', 'events' => [['id' => 2, 'type' => 'completed', 'payload' => ['text' => 'Canonical final']]]])]);
        $this->service->dispatch($this->run);
        $this->assertSame('collecting', $this->run->fresh()->status);
        $this->assertNull($this->run->fresh()->completed_at);
        $this->assertDatabaseMissing('research_messages', ['research_run_id' => $this->run->id, 'role' => 'assistant']);
        $this->service->sync($this->run);
        $this->assertSame('completed', $this->run->fresh()->status);
        $this->assertDatabaseHas('research_messages', ['research_run_id' => $this->run->id, 'role' => 'assistant', 'content' => 'Canonical final']);
        Http::assertSentCount(2);
    }

    public function test_terminal_metadata_requires_event_collection_or_explicit_full_output(): void
    {
        foreach (['completed', 'cancelled', 'failed', 'interrupted'] as $status) {
            $this->run->update(['status' => 'running']);
            $this->service->ingest($this->run, ['status' => $status]);
            $this->assertSame('collecting', $this->run->fresh()->status);
            $this->assertNull($this->run->fresh()->completed_at);
        }
        $this->service->ingest($this->run, ['status' => 'completed', 'output' => 'Explicit full result']);
        $this->assertSame('completed', $this->run->fresh()->status);
        $this->assertDatabaseHas('research_messages', ['research_run_id' => $this->run->id, 'role' => 'assistant', 'content' => 'Explicit full result']);
        $this->service->ingest($this->run, ['status' => 'running']);
        $this->assertSame('completed', $this->run->fresh()->status);
    }

    public function test_empty_final_event_page_finishes_after_prior_pages_without_early_sealing(): void
    {
        $this->run->update(['status' => 'cancel_requested']);
        $this->service->ingest($this->run, ['status' => 'completed', 'has_more' => true, 'events' => [['id' => 1, 'type' => 'completed', 'payload' => ['text' => 'Drained canonical result']]]]);
        $this->assertSame('collecting', $this->run->fresh()->status);
        $this->assertDatabaseMissing('research_messages', ['research_run_id' => $this->run->id, 'role' => 'assistant']);
        $this->service->ingest($this->run, ['status' => 'completed', 'events' => []]);
        $this->assertSame('completed', $this->run->fresh()->status);
        $this->assertDatabaseHas('research_messages', ['research_run_id' => $this->run->id, 'role' => 'assistant', 'content' => 'Drained canonical result']);
    }
}
