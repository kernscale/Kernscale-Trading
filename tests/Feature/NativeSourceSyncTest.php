<?php

namespace Tests\Feature;

use App\Models\IntegrationSnapshot;
use App\Models\ResearchOutput;
use App\Models\User;
use App\Services\TradingDataSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NativeSourceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_native_source_imports_real_contracts_without_inventing_portfolio_data(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(14, 30));
        $account = 'native-fixture';
        config(['trading.bridge_url' => null, 'trading.bridge_token' => 'NEVER_FORWARD',
            'trading.native_url' => 'https://trading.tail84e234.ts.net', 'trading.native_account' => $account,
            'trading.native_account_fingerprint' => hash('sha256', $account)]);
        Role::findOrCreate('owner');
        $owner = User::factory()->create();
        $owner->assignRole('owner');
        $id = '11111111-1111-4111-8111-111111111111';
        $content = '<html><body>Saved native research</body></html>';
        $run = ['id' => $id, 'status' => 'complete', 'symbol' => 'MSFT', 'started_at' => now()->subHour()->timestamp, 'has_report' => true];
        Http::preventStrayRequests();
        Http::fake([
            'trading.tail84e234.ts.net/api/operations*' => Http::response(['ok' => true, 'data' => ['account' => $account, 'observed_at' => now()->timestamp, 'enabled' => true, 'worker_active' => false]], 200, ['Content-Type' => 'application/json']),
            'trading.tail84e234.ts.net/api/research?*' => Http::response(['ok' => true, 'data' => ['account' => $account, 'runs' => [$run, [...$run, 'id' => '22222222-2222-4222-8222-222222222222', 'status' => 'failed', 'has_report' => false]]]], 200, ['Content-Type' => 'application/json']),
            'trading.tail84e234.ts.net/api/research/'.$id.'/report*' => Http::response($content, 200, ['Content-Type' => 'text/html']),
        ]);

        $service = app(TradingDataSync::class);
        $errors = $service->sync();
        $this->assertSame(['portfolio unavailable; saved observation retained.'], $errors);
        $this->assertDatabaseMissing('integration_snapshots', ['kind' => 'portfolio']);
        $this->assertSame(false, IntegrationSnapshot::where('kind', 'agents')->firstOrFail()->payload['worker_active']);
        $reports = IntegrationSnapshot::where('kind', 'native_reports')->firstOrFail()->payload['reports'];
        $this->assertCount(2, $reports);
        $this->assertSame('failed', $reports[1]['status']);
        $output = ResearchOutput::firstOrFail();
        $this->assertSame($content, $output->content);
        $this->assertSame('in_review', $output->status);
        $this->assertSame(hash('sha256', $content), $output->original_checksum);
        $this->assertSame(hash('sha256', $content), $output->provenance['report_sha256']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization') && $request['account'] === $account);
        $service->sync();
        $this->assertDatabaseCount('research_outputs', 1);
    }

    public function test_native_source_does_not_replace_a_configured_private_bridge(): void
    {
        config(['trading.bridge_url' => 'http://bridge.test', 'trading.bridge_token' => str_repeat('x', 40),
            'trading.native_url' => 'https://trading.tail84e234.ts.net', 'trading.native_account' => 'native-fixture',
            'trading.native_account_fingerprint' => hash('sha256', 'native-fixture')]);
        Http::preventStrayRequests();
        Http::fake([
            'bridge.test/v1/portfolio' => Http::response(['observed_at' => now()->toIso8601String(), 'source' => 'native-paper-ledger', 'accounts' => [], 'holdings' => [], 'activities' => [], 'metrics' => [], 'history' => []]),
            'bridge.test/v1/status' => Http::response(['observed_at' => now()->toIso8601String(), 'source' => 'native-paper-research']),
            'bridge.test/v1/reports' => Http::response(['reports' => []]),
        ]);
        $this->assertSame([], app(TradingDataSync::class)->sync());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'tail84e234'));
    }
}
