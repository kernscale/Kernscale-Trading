<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\ResearchEvent;
use App\Models\ResearchMessage;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use App\Services\SessionActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ResearchActivityTest extends TestCase
{
    use RefreshDatabase;

    private function researcher(string $role = 'researcher'): User
    {
        Role::findOrCreate($role);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function savedSession(User $user, string $title = 'Private research'): ResearchSession
    {
        return ResearchSession::create(['user_id' => $user->id, 'title' => $title]);
    }

    private function savedRun(ResearchSession $session, string $status = 'running'): ResearchRun
    {
        return ResearchRun::create(['research_session_id' => $session->id, 'user_id' => $session->user_id, 'status' => $status, 'prompt' => 'Evidence', 'idempotency_key' => fake()->uuid()]);
    }

    private function event(ResearchRun $run, int $remoteId): ResearchEvent
    {
        return ResearchEvent::create(['research_run_id' => $run->id, 'remote_id' => $remoteId, 'type' => 'progress', 'payload' => ['message' => 'Saved activity']]);
    }

    public function test_rows_count_actual_saved_events_for_every_owned_session_in_two_queries(): void
    {
        $author = $this->researcher();
        $empty = $this->savedSession($author, 'Empty');
        $first = $this->savedSession($author, 'First');
        $second = $this->savedSession($author, 'Second');
        $foreign = $this->savedSession($this->researcher(), 'Foreign');
        $firstRun = $this->savedRun($first);
        $read = $this->event($firstRun, 80);
        $this->event($this->savedRun($foreign), 900);
        $new = $this->event($firstRun, 100);
        $secondRun = $this->savedRun($second, 'collecting');
        $secondEvent = $this->event($secondRun, 600);
        ResearchMessage::create(['research_session_id' => $empty->id, 'research_run_id' => $this->savedRun($empty, 'completed')->id, 'role' => 'assistant', 'content' => 'A message is not an event']);
        $first->update(['last_seen_event_id' => $read->id]);
        $sessions = ResearchSession::where('user_id', $author->id)->withCount('runs')->get();
        DB::enableQueryLog();
        $rows = collect(app(SessionActivity::class)->rows($sessions))->keyBy('id');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        $this->assertCount(3, $rows);
        $this->assertFalse($rows->has($foreign->id));
        $this->assertSame(0, $rows[$empty->id]['last_event_id']);
        $this->assertSame(0, $rows[$empty->id]['last_seen_event_id']);
        $this->assertSame(0, $rows[$empty->id]['unread_event_count']);
        $this->assertFalse($rows[$empty->id]['has_unread']);
        $this->assertNull($rows[$empty->id]['active_run']);
        $this->assertSame($new->id, $rows[$first->id]['last_event_id']);
        $this->assertSame($read->id, $rows[$first->id]['last_seen_event_id']);
        $this->assertSame(1, $rows[$first->id]['unread_event_count']);
        $this->assertTrue($rows[$first->id]['has_unread']);
        $this->assertSame(['id' => $firstRun->id, 'status' => 'running'], $rows[$first->id]['active_run']);
        $this->assertSame($secondEvent->id, $rows[$second->id]['last_event_id']);
        $this->assertSame(1, $rows[$second->id]['unread_event_count']);
        $this->assertSame(1, $rows[$second->id]['runs_count']);
    }

    public function test_only_coordinator_active_statuses_are_reported_and_newest_actual_run_wins(): void
    {
        $author = $this->researcher();
        foreach (['queued', 'dispatching', 'waiting', 'running', 'collecting', 'cancel_requested'] as $status) {
            $session = $this->savedSession($author);
            $run = $this->savedRun($session, $status);
            $this->assertSame(['id' => $run->id, 'status' => $status], app(SessionActivity::class)->rows(ResearchSession::whereKey($session->id)->get())[0]['active_run']);
        }
        foreach (['completed', 'failed', 'cancelled', 'interrupted', 'unknown'] as $status) {
            $session = $this->savedSession($author);
            $this->savedRun($session, $status);
            $this->assertNull(app(SessionActivity::class)->rows(ResearchSession::whereKey($session->id)->get())[0]['active_run']);
        }
        $session = $this->savedSession($author);
        $this->savedRun($session, 'running')->update(['created_at' => now()->subMinute()]);
        $newest = $this->savedRun($session, 'waiting');
        $this->savedRun($session, 'completed')->update(['created_at' => now()->addMinute()]);
        $this->assertSame($newest->id, app(SessionActivity::class)->rows(ResearchSession::whereKey($session->id)->get())[0]['active_run']['id']);
        $this->assertSame([], app(SessionActivity::class)->rows(ResearchSession::whereRaw('1 = 0')->get()));
    }

    public function test_index_reports_nonselected_activity_without_acknowledging_it(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->researcher();
        $selected = $this->savedSession($author, 'Selected');
        $background = $this->savedSession($author, 'Background');
        $foreign = $this->savedSession($this->researcher(), 'Foreign secret');
        $event = $this->event($this->savedRun($background), 40);
        $this->event($this->savedRun($foreign), 41);
        $this->actingAs($author)->get('/research?session='.$selected->id)->assertInertia(fn (Assert $page) => $page
            ->component('hub/research')->where('activeSession.id', $selected->id)->has('sessions', 2)
            ->where('sessions', fn (Collection $sessions) => $sessions->contains(fn ($row) => $row['id'] === $background->id && $row['last_event_id'] === $event->id && $row['unread_event_count'] === 1 && $row['has_unread'])))
            ->assertDontSee('Foreign secret');
        $this->assertSame(0, $background->fresh()->last_seen_event_id);
        $this->actingAs($author)->get('/research?session='.$background->id)->assertOk();
        $this->assertSame(0, $background->fresh()->last_seen_event_id);
    }

    public function test_acknowledgements_are_monotonic_and_require_saved_events_from_exact_session(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->researcher();
        $session = $this->savedSession($author);
        $run = $this->savedRun($session);
        $first = $this->event($run, 500);
        $last = $this->event($run, 900);
        $otherOwnEvent = $this->event($this->savedRun($this->savedSession($author)), 1000);
        $foreign = $this->event($this->savedRun($this->savedSession($this->researcher())), 1200);
        $originalUpdatedAt = $session->fresh()->updated_at;
        $this->actingAs($author)->patchJson('/research/sessions/'.$session->id.'/read', ['through_event_id' => (string) $last->id])
            ->assertOk()->assertExactJson(['session_id' => $session->id, 'last_seen_event_id' => $last->id]);
        foreach ([$first->id, 0, $last->id] as $stale) {
            $this->patchJson('/research/sessions/'.$session->id.'/read', ['through_event_id' => $stale])->assertOk()->assertJsonPath('last_seen_event_id', $last->id);
        }
        foreach ([$otherOwnEvent->id, $foreign->id, $foreign->id + 1000, -1, 'invalid'] as $invalid) {
            $this->patchJson('/research/sessions/'.$session->id.'/read', ['through_event_id' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('through_event_id');
        }
        $this->assertSame($last->id, $session->fresh()->last_seen_event_id);
        $this->assertEquals($originalUpdatedAt, $session->fresh()->updated_at);
        $rows = app(SessionActivity::class)->rows(ResearchSession::whereKey($session->id)->get());
        $this->assertSame(0, $rows[0]['unread_event_count']);
        $this->assertFalse($rows[0]['has_unread']);
        $next = $this->event($run, 1500);
        $this->assertSame(1, app(SessionActivity::class)->rows(ResearchSession::whereKey($session->id)->get())[0]['unread_event_count']);
        $this->patchJson('/research/sessions/'.$session->id.'/read', ['through_event_id' => $next->id])->assertOk()->assertJsonPath('last_seen_event_id', $next->id);
    }

    public function test_other_researchers_and_owners_cannot_acknowledge_private_sessions_and_downgrades_are_denied(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $author = $this->researcher();
        $session = $this->savedSession($author);
        foreach ([$this->researcher(), $this->researcher('owner')] as $other) {
            $this->actingAs($other)->patchJson('/research/sessions/'.$session->id.'/read', ['through_event_id' => 0])->assertNotFound();
        }
        Role::findOrCreate('member');
        $author->syncRoles(['member']);
        $this->actingAs($author)->patchJson('/research/sessions/'.$session->id.'/read', ['through_event_id' => 0])->assertForbidden();
        $this->assertSame(0, $session->fresh()->last_seen_event_id);
    }

    public function test_read_endpoint_requires_auth_verified_approved_and_enrolled_membership(): void
    {
        $author = $this->researcher();
        $session = $this->savedSession($author);
        $url = '/research/sessions/'.$session->id.'/read';
        $this->patchJson($url, ['through_event_id' => 0])->assertUnauthorized();
        $this->actingAs($author)->patchJson($url, ['through_event_id' => 0])->assertForbidden();
        $author->update(['email_verified_at' => null]);
        $this->patchJson($url, ['through_event_id' => 0])->assertForbidden();
        $author->update(['email_verified_at' => now(), 'membership_status' => 'revoked']);
        $this->patchJson($url, ['through_event_id' => 0])->assertForbidden();
        $this->assertSame(0, $session->fresh()->last_seen_event_id);
    }
}
