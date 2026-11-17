<?php

namespace App\Services;

use App\Models\ResearchRun;
use App\Models\ResearchSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SessionActivity
{
    /**
     * @param  Collection<int, ResearchSession>  $ownedSessions
     * @return array<int, array<string, mixed>>
     */
    public function rows(Collection $ownedSessions): array
    {
        if ($ownedSessions->isEmpty()) {
            return [];
        }
        $ids = $ownedSessions->modelKeys();
        $activeRuns = ResearchRun::whereIn('research_session_id', $ids)
            ->whereIn('status', ['queued', 'dispatching', 'waiting', 'waiting_for_input', 'running', 'collecting', 'cancel_requested'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'research_session_id', 'status'])->groupBy('research_session_id');
        $activity = DB::table('research_events')
            ->join('research_runs', 'research_runs.id', '=', 'research_events.research_run_id')
            ->join('research_sessions', 'research_sessions.id', '=', 'research_runs.research_session_id')
            ->whereIn('research_sessions.id', $ids)
            ->groupBy('research_sessions.id')
            ->selectRaw('research_sessions.id as session_id, MAX(research_events.id) as last_event_id, SUM(CASE WHEN research_events.id > research_sessions.last_seen_event_id THEN 1 ELSE 0 END) as unread_event_count')
            ->get()->keyBy('session_id');

        return $ownedSessions->map(function (ResearchSession $session) use ($activeRuns, $activity) {
            $run = $activeRuns->get($session->id)?->first();
            $events = $activity->get($session->id);

            return [...$session->toArray(),
                'active_run' => $run ? $run->only(['id', 'status']) : null,
                'last_event_id' => (int) ($events->last_event_id ?? 0),
                'last_seen_event_id' => $session->last_seen_event_id,
                'unread_event_count' => (int) ($events->unread_event_count ?? 0),
                'has_unread' => (int) ($events->unread_event_count ?? 0) > 0,
            ];
        })->values()->all();
    }
}
