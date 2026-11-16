<?php

namespace App\Http\Controllers;

use App\Models\ResearchSession;
use App\Models\WorkspaceProposal;
use App\Services\ResearchCoordinator;
use App\Services\WorkspaceProposals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    public function context(Request $request, ResearchSession $session, ResearchCoordinator $research, WorkspaceProposals $workspace): JsonResponse
    {
        $research->authorize($request->user(), $session);

        return response()->json([...$workspace->captureFor($session), 'proposals' => WorkspaceProposal::where('session_id', $session->id)->latest()->get()]);
    }

    public function thesis(Request $request, ResearchSession $session, ResearchCoordinator $research): RedirectResponse
    {
        $data = $request->validate(['content' => 'present|nullable|string|max:50000', 'version' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $session, $research, $data) {
            $session = ResearchSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $research->authorize($request->user()->fresh(), $session);
            abort_unless($session->thesis_version === (int) $data['version'], 409, 'The thesis changed. Refresh before editing.');
            $session->update(['thesis' => $data['content'] ?? '', 'thesis_version' => $session->thesis_version + 1]);
        });

        return back();
    }

    public function decision(Request $request, WorkspaceProposal $proposal, WorkspaceProposals $workspace): RedirectResponse
    {
        $data = $request->validate(['decision' => 'required|in:apply,reject']);
        $workspace->apply($request->user(), $proposal, $data['decision']);

        return back();
    }
}
