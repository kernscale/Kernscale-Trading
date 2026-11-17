<?php

namespace App\Http\Controllers;

use App\Models\ResearchSession;
use App\Services\ResearchCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SessionReadController extends Controller
{
    public function update(Request $request, ResearchSession $session, ResearchCoordinator $coordinator): JsonResponse
    {
        $coordinator->authorize($request->user(), $session);
        $data = $request->validate(['through_event_id' => 'required|integer|min:0']);
        $cursor = (int) $data['through_event_id'];
        if ($cursor !== 0 && ! DB::table('research_events')
            ->join('research_runs', 'research_runs.id', '=', 'research_events.research_run_id')
            ->where('research_runs.research_session_id', $session->id)->where('research_events.id', $cursor)->exists()) {
            throw ValidationException::withMessages(['through_event_id' => 'Select a saved event from this research session.']);
        }
        DB::table('research_sessions')->where('id', $session->id)
            ->where('last_seen_event_id', '<', $cursor)->update(['last_seen_event_id' => $cursor]);

        return response()->json(['session_id' => $session->id, 'last_seen_event_id' => $session->fresh()->last_seen_event_id]);
    }
}
