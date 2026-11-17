<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use App\Services\ResearchCoordinator;
use App\Services\SessionActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ResearchClarificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ResearchRun $run;

    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        config(['inertia.ssr.enabled' => false, 'trading.bridge_url' => 'http://bridge.test', 'trading.bridge_token' => str_repeat('x', 40)]);
        Http::preventStrayRequests();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->user->assignRole('researcher');
        $session = ResearchSession::create(['user_id' => $this->user->id, 'title' => 'Private']);
        $this->run = ResearchRun::create(['user_id' => $this->user->id, 'research_session_id' => $session->id, 'prompt' => 'Private prompt', 'idempotency_key' => 'clarification', 'status' => 'waiting_for_input']);
        $this->requestId = (string) Str::uuid();
        $this->actingAs($this->user);
    }

    private function url(): string
    {
        return '/research/runs/'.$this->run->id.'/clarifications/'.$this->requestId.'/answer';
    }

    private function receipt(): array
    {
        return ['id' => $this->run->id, 'status' => 'running', 'clarification' => ['request_id' => $this->requestId, 'status' => 'accepted', 'expires_at' => now()->addMinutes(5)->toIso8601String(), 'answered_at' => now()->toIso8601String(), 'consumed_at' => null]];
    }

    public function test_answer_is_forwarded_under_trusted_identity_and_returns_only_safe_receipt(): void
    {
        Http::fake(['bridge.test/*' => Http::response([...$this->receipt(), 'prompt' => 'PRIVATE', 'clarification' => [...$this->receipt()['clarification'], 'answers' => ['PRIVATE']]])]);
        $answers = ['answers' => [['id' => '0', 'answer' => 'Freeform answer']]];
        $this->postJson($this->url(), $answers)->assertOk()->assertExactJson($this->receipt())->assertDontSee('PRIVATE');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'http://bridge.test/v1/researchers/'.$this->user->id.'/runs/'.$this->run->id.'/clarifications/'.$this->requestId.'/answer' && $request->data() === $answers && $request->hasHeader('X-Workspace-ID', 'kernscale-trading'));
        $this->assertSame('running', $this->run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_waiting_for_input_remains_active_and_reconciles_without_dispatch(): void
    {
        $service = app(ResearchCoordinator::class);
        $service->ingest($this->run, ['status' => 'waiting_for_input', 'events' => []]);
        $this->assertSame('waiting_for_input', $this->run->fresh()->status);
        $rows = app(SessionActivity::class)->rows(ResearchSession::whereKey($this->run->research_session_id)->get());
        $this->assertSame(['id' => $this->run->id, 'status' => 'waiting_for_input'], $rows[0]['active_run']);
        $this->post('/research/sessions/'.$this->run->research_session_id.'/runs', ['prompt' => 'New', 'idempotency_key' => 'second'])->assertConflict();
        Http::fake(['bridge.test/*' => Http::response(['status' => 'waiting_for_input', 'events' => [['id' => 1, 'type' => 'clarification_requested', 'payload' => ['request_id' => $this->requestId]]]])]);
        $this->artisan('trading:reconcile')->assertSuccessful();
        $this->assertDatabaseHas('research_events', ['research_run_id' => $this->run->id, 'type' => 'clarification_requested']);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public function test_invalid_answers_and_browser_control_fields_are_rejected_without_http(): void
    {
        foreach ([[], ['answers' => []], ['answers' => [['id' => '3', 'answer' => 'x']]],
            ['answers' => [['id' => 0, 'answer' => 'x']]], ['answers' => [['id' => '0', 'answer' => ['x']]]],
            ['answers' => [['id' => '0', 'answer' => ' ']]], ['answers' => [['id' => '0', 'answer' => 'x', 'question' => 'Forged']]],
            ['answers' => [['id' => '0', 'answer' => 'x'], ['id' => '0', 'answer' => 'y']]],
            ['answers' => [['id' => '0', 'answer' => str_repeat('a', 8193)]]],
            ['answers' => [['id' => '0', 'answer' => 'x']], 'workspace_id' => 'foreign'],
            ['answers' => array_map(fn ($id) => ['id' => $id, 'answer' => str_repeat('界', 8192)], ['0', '1', '2'])],
        ] as $invalid) {
            $this->postJson($this->url(), $invalid)->assertUnprocessable();
        }
        $this->postJson(str_replace($this->requestId, 'invalid', $this->url()), ['answers' => [['id' => '0', 'answer' => 'x']]])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_private_ownership_role_membership_and_security_gates_prevent_delivery(): void
    {
        foreach (['researcher', 'owner'] as $role) {
            $foreign = User::factory()->create();
            $foreign->assignRole($role);
            $this->actingAs($foreign)->postJson($this->url(), ['answers' => [['id' => '0', 'answer' => 'x']]])->assertNotFound();
        }
        $this->actingAs($this->user);
        User::whereKey($this->user->id)->update(['membership_status' => 'revoked']);
        $this->postJson($this->url(), ['answers' => [['id' => '0', 'answer' => 'x']]])->assertForbidden();
        $this->user->refresh()->forceFill(['membership_status' => 'approved'])->save();
        $this->user->syncRoles(['member']);
        $this->actingAs($this->user)->postJson($this->url(), ['answers' => [['id' => '0', 'answer' => 'x']]])->assertForbidden();
        $this->user->syncRoles(['researcher']);
        $this->withMiddleware(EnsureSecurityEnrollment::class);
        $this->postJson($this->url(), ['answers' => [['id' => '0', 'answer' => 'x']]])->assertForbidden();
        $this->post('/logout');
        $this->postJson($this->url(), ['answers' => [['id' => '0', 'answer' => 'x']]])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_upstream_expiry_conflict_validation_and_absence_keep_status_honest(): void
    {
        $sequence = Http::sequence();
        foreach ([404, 409, 422, 500] as $status) {
            $sequence->push(['error' => 'PRIVATE upstream detail'], $status);
        }
        Http::fake(['bridge.test/*' => $sequence]);
        foreach ([404, 409, 422, 500] as $status) {
            $this->postJson($this->url(), ['answers' => [['id' => '0', 'answer' => 'x']]])->assertStatus($status === 500 ? 503 : $status)->assertDontSee('PRIVATE');
            $this->assertSame('waiting_for_input', $this->run->fresh()->status);
        }
    }

    public function test_untrusted_receipt_identity_or_timestamps_cannot_mutate_run_or_leak_payload(): void
    {
        $sequence = Http::sequence();
        foreach ([['id' => (string) Str::uuid()], ['clarification' => [...$this->receipt()['clarification'], 'request_id' => (string) Str::uuid()]],
            ['clarification' => [...$this->receipt()['clarification'], 'expires_at' => 'tomorrow']], ['status' => 'unknown'],
        ] as $change) {
            $sequence->push([...$this->receipt(), ...$change, 'private' => 'PRIVATE']);
        }
        Http::fake(['bridge.test/*' => $sequence]);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson($this->url(), ['answers' => [['id' => '0', 'answer' => 'x']]])->assertStatus(502)->assertDontSee('PRIVATE');
            $this->assertSame('waiting_for_input', $this->run->fresh()->status);
        }
    }

    public function test_timeout_allows_same_answer_retry_without_resubmitting_prompt(): void
    {
        $calls = [];
        Http::fake(function ($request) use (&$calls) {
            $calls[] = $request->data();
            if (count($calls) === 1) {
                throw new ConnectionException('PRIVATE ambiguous timeout');
            }

            return Http::response($this->receipt());
        });
        $body = ['answers' => [['id' => '0', 'answer' => 'Exact retry']]];
        $this->postJson($this->url(), $body)->assertServiceUnavailable()->assertDontSee('PRIVATE');
        $this->assertSame('waiting_for_input', $this->run->fresh()->status);
        $this->postJson($this->url(), $body)->assertOk()->assertJsonPath('clarification.status', 'accepted')->assertJsonPath('clarification.consumed_at', null);
        $this->assertSame([$body, $body], $calls);
        Queue::assertNothingPushed();
    }

    public function test_waiting_input_cancellation_and_late_answer_do_not_undo_pending_or_terminal_state(): void
    {
        Http::fake(['bridge.test/*' => Http::sequence()->push(['status' => 'cancel_requested'])->push($this->receipt())->push($this->receipt())]);
        app(ResearchCoordinator::class)->cancel($this->run);
        $this->assertSame('cancel_requested', $this->run->fresh()->status);
        $this->assertNull($this->run->fresh()->completed_at);
        app(ResearchCoordinator::class)->ingest($this->run, ['status' => 'waiting_for_input']);
        $this->assertSame('cancel_requested', $this->run->fresh()->status);
        $body = ['answers' => [['id' => '0', 'answer' => 'x']]];
        $this->postJson($this->url(), $body)->assertOk()->assertJsonPath('status', 'cancel_requested');
        $this->run->update(['status' => 'cancelled']);
        $this->post($this->url(), $body)->assertRedirect()->assertSessionHas('status', 'Clarification response received.');
        $this->assertSame('cancelled', $this->run->fresh()->status);
    }

    public function test_revocation_includes_waiting_input_in_durable_pending_cancellation(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        $this->delete('/admin/members/'.$this->user->id)->assertRedirect();
        $this->assertSame('cancel_requested', $this->run->fresh()->status);
        $this->assertNull($this->run->fresh()->completed_at);
        Http::assertNothingSent();
    }

    public function test_coordinator_reloads_actor_before_answer_delivery(): void
    {
        User::whereKey($this->user->id)->update(['membership_status' => 'revoked']);
        try {
            app(ResearchCoordinator::class)->answer($this->user, $this->run, $this->requestId, [['id' => '0', 'answer' => 'x']]);
            $this->fail('A stale authenticated actor must not deliver an answer.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_terminal_metadata_receipt_cannot_skip_ordered_event_collection_or_seal_partial_text(): void
    {
        $this->run->events()->create(['remote_id' => 1, 'type' => 'token', 'payload' => ['text' => 'Partial text']]);
        Http::fake(['bridge.test/*' => Http::sequence()
            ->push([...$this->receipt(), 'status' => 'completed'])
            ->push(['status' => 'completed', 'events' => [['id' => 2, 'type' => 'completed', 'payload' => ['text' => 'Canonical final text']]]])]);
        $this->postJson($this->url(), ['answers' => [['id' => '0', 'answer' => 'x']]])->assertOk()->assertJsonPath('status', 'collecting');
        $this->assertDatabaseMissing('research_messages', ['research_run_id' => $this->run->id, 'role' => 'assistant']);
        $this->getJson('/research/runs/'.$this->run->id.'/events')->assertOk()->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('research_messages', ['research_run_id' => $this->run->id, 'role' => 'assistant', 'content' => 'Canonical final text']);
    }

    public function test_unicode_answers_within_utf8_transport_bound_are_forwarded_unchanged(): void
    {
        Http::fake(['bridge.test/*' => Http::response($this->receipt())]);
        $body = ['answers' => [['id' => '0', 'answer' => str_repeat('界', 8192)], ['id' => '1', 'answer' => str_repeat('界', 8192)]]];
        $this->assertLessThan(65536, strlen(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $this->postJson($this->url(), $body)->assertOk();
        Http::assertSent(fn ($request) => $request->data() === $body);
        Http::assertSentCount(1);
    }
}
