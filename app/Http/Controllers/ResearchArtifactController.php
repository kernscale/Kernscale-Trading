<?php

namespace App\Http\Controllers;

use App\Models\ResearchOutput;
use App\Models\ResearchRun;
use App\Services\ResearchArtifacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResearchArtifactController extends Controller
{
    public function derive(Request $request, ResearchOutput $output, ResearchArtifacts $artifacts): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'kind' => ['required', Rule::in(['research', 'strategy', 'document'])],
            'version' => 'required|integer|min:1', 'idempotency_key' => 'required|string|max:100', 'session_id' => 'nullable|uuid',
            'content' => 'prohibited', 'sources' => 'prohibited', 'provenance' => 'prohibited']);
        [$draft, $created] = $artifacts->derive($request->user(), $output, $data['title'], $data['kind'],
            (int) $data['version'], $data['idempotency_key'], $data['session_id'] ?? null);
        if ($request->expectsJson()) {
            return response()->json(['created' => $created, 'output' => $draft->only([
                'id', 'title', 'kind', 'status', 'version', 'content', 'sources', 'research_session_id',
                'research_run_id', 'source_output_id', 'source_output_version', 'original_checksum', 'provenance',
            ])], $created ? 201 : 200);
        }

        return redirect('/outputs?output='.$draft->id);
    }

    public function store(Request $request, ResearchRun $run, ResearchArtifacts $artifacts): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['title' => 'required|string|max:200', 'kind' => ['required', Rule::in(['research', 'strategy', 'document'])],
            'content' => 'prohibited', 'sources' => 'prohibited', 'provenance' => 'prohibited', 'research_run_id' => 'prohibited']);
        [$output, $created] = $artifacts->save($request->user(), $run, $data['title'], $data['kind']);
        if ($request->expectsJson()) {
            return response()->json(['created' => $created, 'output' => $output->only([
                'id', 'title', 'kind', 'status', 'version', 'content', 'sources', 'research_run_id',
                'research_session_id', 'original_checksum', 'provenance',
            ])], $created ? 201 : 200);
        }

        return redirect('/outputs?output='.$output->id);
    }
}
