<?php

namespace App\Http\Controllers;

use App\Models\CommunityComment;
use App\Models\CommunityTopic;
use App\Models\ResearchOutput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string',
            'output_id' => 'nullable|required_with:output_version|uuid',
            'output_version' => 'nullable|required_with:output_id|integer|min:1',
        ]);
        DB::transaction(function () use ($request, $data) {
            $link = [];
            if (! empty($data['output_id'])) {
                $output = ResearchOutput::visibleTo($request->user())->whereKey($data['output_id'])
                    ->where('status', 'published')->where('version', $data['output_version'])->lockForUpdate()->first();
                if ($output === null) {
                    throw ValidationException::withMessages(['output_id' => 'Select a currently published output and its current version.']);
                }
                $link = ['output_id' => $output->id, 'output_version' => $output->version, 'output_title' => $output->title, 'output_kind' => $output->kind];
            }
            CommunityTopic::create(['title' => $data['title'], 'body' => $data['body'], 'user_id' => $request->user()->id, ...$link]);
        });

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
