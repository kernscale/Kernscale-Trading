<?php

namespace Tests\Feature;

use App\Jobs\DispatchResearch;
use App\Models\ResearchSession;
use App\Models\User;
use App\Services\ResearchCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ResearchLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function researcher(): User
    {
        $user = User::factory()->create(['membership_status' => 'approved']);
        Role::findOrCreate('researcher');
        $user->assignRole('researcher');

        return $user;
    }

    public function test_replay_creates_one_durable_run_and_one_user_message(): void
    {
        Queue::fake();
        $user = $this->researcher();
        $session = ResearchSession::create(['user_id' => $user->id, 'title' => 'Liquidity']);
        $coordinator = app(ResearchCoordinator::class);
        $run = $coordinator->start($user, $session, 'Compare the evidence', 'request-123');
        $replay = $coordinator->start($user, $session, 'Compare the evidence', 'request-123');

        $this->assertSame($run->id, $replay->id);
        $this->assertDatabaseCount('research_runs', 1);
        $this->assertDatabaseCount('research_messages', 1);
        Queue::assertPushed(DispatchResearch::class, 1);
    }

    public function test_reusing_request_key_with_changed_prompt_is_rejected(): void
    {
        Queue::fake();
        $user = $this->researcher();
        $session = ResearchSession::create(['user_id' => $user->id, 'title' => 'Liquidity']);
        $service = app(ResearchCoordinator::class);
        $service->start($user, $session, 'First', 'request-123');
        $this->expectException(HttpException::class);
        $service->start($user, $session, 'Different', 'request-123');
    }

    public function test_other_researchers_cannot_dispatch_into_a_private_session(): void
    {
        Queue::fake();
        $owner = $this->researcher();
        $other = $this->researcher();
        $session = ResearchSession::create(['user_id' => $owner->id, 'title' => 'Private']);
        $this->expectException(HttpException::class);
        app(ResearchCoordinator::class)->start($other, $session, 'Read secrets', 'request-456');
    }

    public function test_revoked_researcher_is_not_dispatched_by_worker(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = $this->researcher();
        $session = ResearchSession::create(['user_id' => $user->id, 'title' => 'Private']);
        $run = app(ResearchCoordinator::class)->start($user, $session, 'Analyze', 'request-789');
        $user->forceFill(['membership_status' => 'revoked'])->save();
        (new DispatchResearch($run->id))->handle(app(ResearchCoordinator::class));
        $this->assertSame('cancelled', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_event_replay_deduplicates_and_completion_saves_one_reply(): void
    {
        Queue::fake();
        $user = $this->researcher();
        $session = ResearchSession::create(['user_id' => $user->id, 'title' => 'Private']);
        $service = app(ResearchCoordinator::class);
        $run = $service->start($user, $session, 'Analyze', 'request-789');
        $events = [['id' => 1, 'type' => 'token', 'payload' => ['text' => 'Evidence supports hold.']]];
        $service->ingest($run, ['status' => 'completed', 'events' => $events]);
        $service->ingest($run, ['status' => 'completed', 'events' => $events]);
        $this->assertDatabaseCount('research_events', 1);
        $this->assertDatabaseCount('research_messages', 2);
        $this->assertSame('Evidence supports hold.', $session->messages()->where('role', 'assistant')->first()->content);
    }

    public function test_terminal_reply_waits_until_all_event_pages_are_collected(): void
    {
        Queue::fake();
        $user = $this->researcher();
        $session = ResearchSession::create(['user_id' => $user->id, 'title' => 'Paged evidence']);
        $service = app(ResearchCoordinator::class);
        $run = $service->start($user, $session, 'Analyze', 'paged-request');
        $service->ingest($run, ['status' => 'completed', 'has_more' => true, 'events' => [['id' => 1, 'type' => 'token', 'payload' => ['text' => 'Partial']]]]);
        $this->assertSame('collecting', $run->fresh()->status);
        $this->assertSame(0, $session->messages()->where('role', 'assistant')->count());
        $service->ingest($run, ['status' => 'completed', 'has_more' => false, 'events' => [['id' => 2, 'type' => 'completed', 'payload' => ['text' => 'Complete canonical reply.']]]]);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('Complete canonical reply.', $session->messages()->where('role', 'assistant')->first()->content);
    }
}
