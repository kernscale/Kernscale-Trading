<?php

namespace App\Http\Controllers;

use App\Models\ResearchOutput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutputHistoryController extends Controller
{
    public function index(Request $request, ResearchOutput $output): JsonResponse
    {
        abort_unless($output->user_id === $request->user()->id, 404);

        return response()->json([
            'output_id' => $output->id,
            'current_version' => $output->version,
            'revisions' => DB::table('output_revisions')
                ->where('research_output_id', $output->id)
                ->orderByDesc('version')
                ->get(['version', 'snapshot', 'created_at'])
                ->map(fn ($revision) => [
                    'version' => (int) $revision->version,
                    'snapshot' => json_decode($revision->snapshot, true, 512, JSON_THROW_ON_ERROR),
                    'created_at' => $revision->created_at,
                ]),
        ]);
    }
}
