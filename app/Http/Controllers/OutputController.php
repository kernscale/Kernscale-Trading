<?php

namespace App\Http\Controllers;

use App\Models\IntegrationSnapshot;
use App\Models\ResearchOutput;
use App\Models\ResearchSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OutputController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('hub/outputs', ['outputs' => ResearchOutput::visibleTo($request->user())->with('author')->latest()->get(), 'runs' => IntegrationSnapshot::where('kind', 'native_reports')->first()?->payload['reports'] ?? []]);
    }

    public function create(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['researcher', 'owner']), 403);
        $data = $request->validate(['title' => 'required|string|max:200', 'kind' => ['required', Rule::in(['research', 'strategy', 'document'])], 'content' => 'required|string', 'sources' => 'sometimes|array', 'sources.*.url' => 'required|url:http,https', 'sources.*.title' => 'sometimes|string|max:250', 'session_id' => 'nullable|uuid']);
        if (! empty($data['session_id'])) {
            ResearchSession::where('user_id', $request->user()->id)->findOrFail($data['session_id']);
        }
        $output = ResearchOutput::create([...array_diff_key($data, ['session_id' => true]), 'research_session_id' => $data['session_id'] ?? null, 'user_id' => $request->user()->id, 'original_checksum' => hash('sha256', $data['content'])]);
        $this->audit($request, 'output.created', $output);

        return redirect('/outputs');
    }

    public function update(Request $request, ResearchOutput $output): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['researcher', 'owner']), 403);
        abort_unless($output->user_id === $request->user()->id, 404);
        $data = $request->validate(['title' => 'required|string|max:200', 'content' => 'required|string', 'version' => 'required|integer|min:1']);
        $data['version'] = (int) $data['version'];
        DB::transaction(function () use ($request, $output, $data) {
            $current = ResearchOutput::whereKey($output->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($current->status, ['draft', 'changes_requested'], true) && $current->version === $data['version'] && $current->external_id === null, 409, 'Only the current editable draft can be revised.');
            DB::table('output_revisions')->insert(['research_output_id' => $current->id, 'user_id' => $request->user()->id, 'version' => $current->version, 'snapshot' => json_encode($current->only(['title', 'content', 'sources', 'status']), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            $current->update([...array_diff_key($data, ['version' => true]), 'version' => $current->version + 1, 'status' => 'draft']);
            $this->audit($request, 'output.revised', $current);
        });

        return back();
    }

    public function submit(Request $request, ResearchOutput $output): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['researcher', 'owner']), 403);
        abort_unless($output->user_id === $request->user()->id, 404);
        $count = ResearchOutput::whereKey($output->id)->whereIn('status', ['draft', 'changes_requested'])->update(['status' => 'in_review', 'updated_at' => now()]);
        abort_unless($count === 1, 409);
        $this->audit($request, 'output.submitted', $output);

        return back();
    }

    public function review(Request $request, ResearchOutput $output): RedirectResponse
    {
        abort_unless($request->user()->hasRole('owner'), 403);
        $data = $request->validate(['decision' => ['required', Rule::in(['publish', 'changes_requested'])], 'comment' => 'nullable|string', 'version' => 'required|integer|min:1']);
        $data['version'] = (int) $data['version'];
        DB::transaction(function () use ($request, $output, $data) {
            $current = ResearchOutput::whereKey($output->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'in_review' && $current->version === $data['version'], 409, 'This output changed. Review the current revision before deciding.');
            $current->update(['status' => $data['decision'] === 'publish' ? 'published' : 'changes_requested', 'review_comment' => $data['comment'] ?? null, 'reviewed_by' => $request->user()->id, 'published_at' => $data['decision'] === 'publish' ? now() : null]);
            $this->audit($request, 'output.'.$data['decision'], $current);
        });

        return back();
    }

    private function audit(Request $request, string $action, ResearchOutput $output): void
    {
        DB::table('audit_entries')->insert(['user_id' => $request->user()->id, 'action' => $action, 'subject_id' => $output->id, 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }
}
