<?php

namespace App\Http\Controllers;

use App\Models\BoardCard;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Services\ResearchCoordinator;
use App\Services\WorkspaceProposals;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ResearchController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasAnyRole(['researcher', 'owner']), 403);
        $sessions = ResearchSession::where('user_id', $request->user()->id)->withCount('runs')->orderByDesc('pinned')->latest('updated_at')->get();
        $selection = $request->validate(['session' => 'sometimes|uuid']);
        $active = null;
        if ($request->filled('session')) {
            $active = ResearchSession::where('user_id', $request->user()->id)->with(['messages', 'runs', 'board', 'proposals'])->whereKey($selection['session'])->firstOrFail();
        } elseif ($sessions->isNotEmpty()) {
            $active = $sessions->first()->load(['messages', 'runs', 'board', 'proposals']);
        }
        $events = $active ? $active->runs->flatMap(fn ($run) => $run->events()->get())->sortBy('id')->values() : [];

        return Inertia::render('hub/research', ['sessions' => $sessions, 'activeSession' => $active, 'events' => $events]);
    }

    public function create(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['researcher', 'owner']), 403);
        $data = $request->validate(['title' => 'required|string|max:160']);
        $session = ResearchSession::create([...$data, 'user_id' => $request->user()->id]);

        return redirect('/research?session='.$session->id);
    }

    public function update(Request $request, ResearchSession $session, ResearchCoordinator $service): RedirectResponse
    {
        $service->authorize($request->user(), $session);
        $data = $request->validate(['title' => 'required_without:pinned|string|max:160', 'pinned' => 'sometimes|boolean']);
        $session->update($data);

        return back();
    }

    public function run(Request $request, ResearchSession $session, ResearchCoordinator $service): RedirectResponse
    {
        $data = $request->validate(['prompt' => 'required|string', 'idempotency_key' => 'required|string|max:100']);
        $service->start($request->user(), $session, $data['prompt'], $data['idempotency_key']);

        return back();
    }

    public function events(Request $request, ResearchRun $run, ResearchCoordinator $service): JsonResponse
    {
        $service->authorize($request->user(), $run->session);
        $data = $request->validate(['after' => 'sometimes|integer|min:0']);
        try {
            $service->sync($run);
        } catch (ConnectionException|RequestException|\RuntimeException $error) {
            // Saved events stay available while a backend is unavailable.
        }

        return response()->json(['id' => $run->id, 'status' => $run->fresh()->status, 'events' => $run->events()->where('id', '>', $data['after'] ?? 0)->get(), 'workspace' => [...app(WorkspaceProposals::class)->captureFor($run->session), 'proposals' => $run->session->proposals()->get()]]);
    }

    public function cancel(Request $request, ResearchRun $run, ResearchCoordinator $service): RedirectResponse
    {
        $service->authorize($request->user(), $run->session);
        try {
            $service->cancel($run);
        } catch (ConnectionException|RequestException|\RuntimeException $error) {
            return back()->withErrors(['research' => 'Cancellation is pending backend confirmation.']);
        }

        return back();
    }

    public function card(Request $request, ResearchSession $session, ResearchCoordinator $service): RedirectResponse
    {
        $service->authorize($request->user(), $session);
        $data = $request->validate(['title' => 'required|string|max:160', 'content' => 'sometimes|string', 'status' => ['sometimes', Rule::in(['backlog', 'researching', 'review', 'done'])]]);
        BoardCard::create([...$data, 'research_session_id' => $session->id]);

        return back();
    }

    public function updateCard(Request $request, ResearchSession $session, BoardCard $card, ResearchCoordinator $service): RedirectResponse
    {
        $service->authorize($request->user(), $session);
        abort_unless($card->research_session_id === $session->id, 404);
        $data = $request->validate(['version' => 'required|integer|min:1', 'title' => 'sometimes|string|max:160', 'content' => 'sometimes|string', 'status' => ['sometimes', Rule::in(['backlog', 'researching', 'review', 'done'])]]);
        $count = BoardCard::whereKey($card->id)->where('version', $data['version'])->update([...array_diff_key($data, ['version' => true]), 'version' => $data['version'] + 1, 'updated_at' => now()]);
        abort_unless($count === 1, 409, 'This card was updated elsewhere. Refresh before editing.');

        return back();
    }
}
