<?php

namespace App\Http\Controllers;

use App\Models\CommunityComment;
use App\Models\CommunityTopic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommunityController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('hub/community', ['topics' => CommunityTopic::with(['user', 'comments'])->latest()->get()]);
    }

    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'body' => 'required|string']);
        CommunityTopic::create([...$data, 'user_id' => $request->user()->id]);

        return back();
    }

    public function comment(Request $request, CommunityTopic $topic): RedirectResponse
    {
        abort_if($topic->locked && ! $request->user()->hasRole('owner'), 403, 'Discussion is locked.');
        $data = $request->validate(['body' => 'required|string']);
        CommunityComment::create([...$data, 'community_topic_id' => $topic->id, 'user_id' => $request->user()->id]);

        return back();
    }

    public function lock(Request $request, CommunityTopic $topic): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);
        $data = $request->validate(['locked' => 'required|boolean']);
        $topic->update($data);

        return back();
    }
}
