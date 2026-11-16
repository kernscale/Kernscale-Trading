<?php

namespace Tests\Feature;

use App\Models\IntegrationSnapshot;
use App\Models\ResearchOutput;
use App\Models\User;
use App\Services\PortfolioObservations;
use App\Services\TradingDataSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SnapshotSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_keeps_saved_data_on_outage_and_never_overwrites_with_older_observation(): void
    {
        config(['trading.bridge_url' => 'http://bridge.test', 'trading.bridge_token' => str_repeat('t', 32)]);
        $payload = ['observed_at' => '2026-11-16T10:00:00Z', 'source' => 'native-paper', 'accounts' => [], 'holdings' => [], 'activities' => [], 'metrics' => [], 'history' => []];
        Http::fake(['*/v1/portfolio' => Http::response($payload), '*/v1/status' => Http::response(['observed_at' => $payload['observed_at'], 'runs' => []]), '*/v1/reports' => Http::response(['reports' => []])]);
        app(TradingDataSync::class)->sync();
        $this->assertSame($payload, IntegrationSnapshot::where('kind', 'portfolio')->first()->payload);
        Http::fake(['*/v1/portfolio' => Http::response([...$payload, 'observed_at' => '2026-11-16T09:00:00Z']), '*' => Http::response([], 503)]);
        app(TradingDataSync::class)->sync();
        $this->assertSame($payload, IntegrationSnapshot::where('kind', 'portfolio')->first()->payload);
    }

    public function test_saved_reports_import_idempotently_and_keep_original_receipts_immutable(): void
    {
        $user = User::factory()->create();
        $service = app(TradingDataSync::class);
        $report = ['id' => 'native-run-123', 'title' => 'Native research', 'content' => 'Original evidence', 'sources' => [], 'created_at' => '2026-11-16T10:00:00Z'];
        $service->importReport($report, $user->id);
        $service->importReport($report, $user->id);
        $this->assertDatabaseCount('research_outputs', 1);
        $service->importReport([...$report, 'content' => 'Changed immutable receipt'], $user->id);
        $this->assertSame('Original evidence', ResearchOutput::first()->content);
        $this->assertSame('in_review', ResearchOutput::first()->status);
    }

    public function test_accepted_fresh_native_snapshot_records_one_observed_valuation(): void
    {
        $payload = ['observed_at' => '2026-11-16T10:02:10Z', 'source' => 'native-paper-ledger', 'provenance' => ['account' => 'global-paper'],
            'accounts' => [], 'holdings' => [], 'activities' => [], 'metrics' => ['equity' => 10000, 'cash' => 10000, 'deposits' => 10000],
            'history' => [], 'freshness' => ['stale' => false, 'market_asof' => null]];
        $service = app(TradingDataSync::class);
        $service->snapshot('portfolio', $payload);
        $service->snapshot('portfolio', $payload);
        $this->assertDatabaseCount('portfolio_valuations', 1);
        $history = app(PortfolioObservations::class)->history('global-paper');
        $this->assertSame('2026-11-16T10:02:10+00:00', $history[0]['observed_at']);
        $this->assertSame('10000.00000000', $history[0]['equity']);
    }
}
