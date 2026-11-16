<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            foreach (['users', 'passkeys', 'invitations', 'research_sessions', 'research_runs', 'research_messages', 'research_events', 'research_outputs', 'board_cards', 'community_topics', 'watchlist_items', 'legacy_import_receipts'] as $table) {
                if (! Schema::hasTable($table)) {
                    throw new \RuntimeException('Schema migration is incomplete.');
                }
            }
            $migrator = app('migrator');
            if (array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan())) {
                throw new \RuntimeException('Migrations are pending.');
            }
            if (app()->isProduction() && (! preg_match('/^[a-f0-9]{40}$/', config('trading.release_commit') ?? '') || ! preg_match('/@sha256:[a-f0-9]{64}$/', config('trading.release_image') ?? '') || ! config('app.key') || parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https' || config('session.secure') !== true)) {
                throw new \RuntimeException('Release configuration is incomplete.');
            }
            if (app()->isProduction()) {
                Invitation::ensureProductionDelivery();
            }
        } catch (\Throwable $error) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ready', 'release' => ['commit' => config('trading.release_commit'), 'image' => config('trading.release_image')], 'research' => config('trading.bridge_url') ? 'configured' : 'unconfigured']);
    }
}
