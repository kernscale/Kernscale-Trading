<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSecurityEnrollment;
use App\Models\IntegrationSnapshot;
use App\Models\PortfolioValuation;
use App\Models\User;
use App\Services\PortfolioObservations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ValuationHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 30));
    }

    private function observation(string $at = '2026-11-16T12:01:23+00:00'): array
    {
        return ['observed_at' => $at, 'source' => 'native-paper-ledger',
            'provenance' => ['account' => 'global-paper', 'read_only' => true],
            'metrics' => ['equity' => 10025.5, 'cash' => 9025.5, 'deposits' => 10000],
            'freshness' => ['stale' => false, 'market_asof' => '2026-11-16T12:00:30+00:00'],
            'history' => [['date' => '2020-01-01', 'equity' => 999999]],
        ];
    }

    public function test_first_real_observation_per_utc_bucket_is_retained_and_replay_is_idempotent(): void
    {
        $service = app(PortfolioObservations::class);
        $payload = $this->observation();
        $service->record($payload);
        $service->record($payload);
        $changed = $this->observation('2026-11-16T13:04:59+01:00');
        $changed['metrics']['equity'] = 20000;
        $service->record($changed);
        $this->assertDatabaseCount('portfolio_valuations', 1);
        $row = PortfolioValuation::firstOrFail();
        $this->assertSame('10025.50000000', $row->equity);
        $this->assertSame('2026-11-16T12:01:23+00:00', $row->observed_at->toIso8601String());
        $this->assertSame('2026-11-16T12:00:00+00:00', $row->bucket_at->toIso8601String());
        $this->assertSame('2026-11-16T12:00:30+00:00', $row->market_asof->toIso8601String());
    }

    public function test_distinct_buckets_preserve_source_capture_times_and_never_backfill_embedded_history(): void
    {
        $service = app(PortfolioObservations::class);
        $service->record($this->observation('2026-11-16T12:06:07+00:00'));
        $service->record($this->observation());
        $history = $service->history('global-paper');
        $this->assertCount(2, $history);
        $this->assertSame(['2026-11-16T12:01:23+00:00', '2026-11-16T12:06:07+00:00'], array_column($history, 'observed_at'));
        $this->assertSame('10025.50000000', $history[0]['equity']);
        $this->assertSame('native-paper-ledger', $history[0]['source']);
        $this->assertSame([], $service->history('other-account'));
        $this->assertDatabaseCount('portfolio_valuations', 2);
    }

    public function test_stale_unknown_nonfinite_untrusted_and_future_observations_are_ignored(): void
    {
        foreach (['stale', 'missing', 'nan', 'source', 'account', 'future', 'empty_time', 'relative_time', 'invalid_date', 'missing_staleness', 'bad_market'] as $invalid) {
            $payload = $this->observation();
            match ($invalid) {
                'stale' => $payload['freshness']['stale'] = true,
                'missing' => $payload['metrics']['equity'] = null,
                'nan' => $payload['metrics']['equity'] = NAN,
                'source' => $payload['source'] = 'backtest',
                'account' => $payload['provenance']['account'] = '',
                'future' => $payload['observed_at'] = '2099-01-01T00:00:00Z',
                'empty_time' => $payload['observed_at'] = '',
                'relative_time' => $payload['observed_at'] = 'yesterday',
                'invalid_date' => $payload['observed_at'] = '2026-02-30T12:00:00Z',
                'missing_staleness' => $payload['freshness']['stale'] = null,
                'bad_market' => $payload['freshness']['market_asof'] = '2099-01-01T00:00:00Z',
            };
            app(PortfolioObservations::class)->record($payload);
        }
        $this->assertDatabaseCount('portfolio_valuations', 0);
    }

    public function test_recording_is_only_a_thin_projection_and_does_not_replace_native_snapshot(): void
    {
        $payload = $this->observation();
        IntegrationSnapshot::create(['kind' => 'portfolio', 'payload' => $payload, 'observed_at' => $payload['observed_at']]);
        $original = IntegrationSnapshot::firstOrFail()->getRawOriginal('payload');
        app(PortfolioObservations::class)->record($payload);
        $this->assertSame($original, IntegrationSnapshot::firstOrFail()->getRawOriginal('payload'));
        $row = PortfolioValuation::firstOrFail();
        $this->assertArrayNotHasKey('payload', $row->getAttributes());
        $this->assertArrayNotHasKey('history', $row->provenance);
        $this->assertSame('9025.50000000', $row->cash);
        $this->assertSame('10000.00000000', $row->deposits);
    }

    public function test_full_period_retains_older_actual_endpoints_before_bounded_downsampling(): void
    {
        $rows = [];
        $start = CarbonImmutable::parse('2026-11-12T00:00:00Z');
        for ($index = 0; $index < 1002; $index++) {
            $at = $start->addMinutes($index * 5);
            $rows[] = ['account' => 'global-paper', 'bucket_at' => $at, 'observed_at' => $at,
                'equity' => 10000 + $index, 'source' => 'native-paper-ledger',
                'digest' => hash('sha256', (string) $index), 'provenance' => '{}'];
        }
        PortfolioValuation::insert($rows);
        $history = app(PortfolioObservations::class)->history('global-paper');
        $this->assertLessThanOrEqual(1000, count($history));
        $this->assertSame($start->toIso8601String(), $history[0]['observed_at']);
        $this->assertSame($start->addMinutes(1001 * 5)->toIso8601String(), $history[array_key_last($history)]['observed_at']);
        $this->assertDatabaseCount('portfolio_valuations', 1002);
    }

    public function test_supported_periods_span_the_full_window_with_original_bin_last_points(): void
    {
        $anchor = CarbonImmutable::parse('2026-11-16T12:30:00Z');
        $rows = [];
        for ($day = 0; $day <= 366; $day++) {
            foreach ([0, 1] as $offset) {
                $at = $anchor->subDays($day)->subMinutes($offset * 5);
                $rows[] = ['account' => 'global-paper', 'bucket_at' => $at, 'observed_at' => $at,
                    'market_asof' => $at->subSeconds(15), 'equity' => 10000 + $day * 10 + $offset,
                    'source' => 'native-paper-ledger', 'digest' => hash('sha256', $at->toIso8601String()), 'provenance' => '{}'];
            }
        }
        PortfolioValuation::insert($rows);
        foreach (['weekly' => 7, 'monthly' => 30, 'quarterly' => 90, 'yearly' => 365] as $period => $days) {
            $history = app(PortfolioObservations::class)->history('global-paper', $period);
            $this->assertSame($anchor->subDays($days)->toIso8601String(), $history[0]['observed_at']);
            $this->assertSame($anchor->toIso8601String(), $history[array_key_last($history)]['observed_at']);
            $this->assertCount($days + 1, $history);
            $this->assertLessThanOrEqual(1000, count($history));
            $this->assertSame('10000.00000000', $history[array_key_last($history)]['equity']);
            $this->assertSame($anchor->subSeconds(15)->toIso8601String(), $history[array_key_last($history)]['market_asof']);
        }
        $this->assertDatabaseCount('portfolio_valuations', 734);
    }

    public function test_history_ignores_other_accounts_and_unverified_sources_when_anchoring(): void
    {
        $service = app(PortfolioObservations::class);
        $service->record($this->observation());
        $other = $this->observation('2026-11-16T12:10:00Z');
        $other['provenance']['account'] = 'other-account';
        $service->record($other);
        $row = PortfolioValuation::firstOrFail()->replicate();
        $row->forceFill(['source' => 'backtest', 'observed_at' => '2099-01-01T00:00:00Z', 'bucket_at' => '2099-01-01T00:00:00Z'])->save();
        $history = $service->history('global-paper', 'yearly');
        $this->assertCount(1, $history);
        $this->assertSame('native-paper-ledger', $history[0]['source']);
        $this->assertSame('2026-11-16T12:01:23+00:00', $history[0]['observed_at']);
    }

    public function test_both_http_payloads_honor_the_existing_period_query_and_preserve_metrics(): void
    {
        $this->withoutMiddleware(EnsureSecurityEnrollment::class);
        $this->actingAs(User::factory()->create());
        $service = app(PortfolioObservations::class);
        $old = $this->observation('2026-11-01T12:01:23Z');
        $old['freshness']['market_asof'] = '2026-11-01T12:00:30Z';
        $service->record($old);
        $current = $this->observation();
        $service->record($current);
        IntegrationSnapshot::create(['kind' => 'portfolio', 'payload' => $current, 'observed_at' => $current['observed_at']]);
        foreach (['/dashboard' => 'portfolio', '/portfolio' => 'snapshot'] as $path => $prop) {
            $this->get($path.'?period=weekly')->assertOk()->assertInertia(fn (Assert $page) => $page->has($prop.'.history', 1)->where($prop.'.metrics', $current['metrics']));
            $this->get($path.'?period=yearly')->assertOk()->assertInertia(fn (Assert $page) => $page->has($prop.'.history', 2)->where($prop.'.history.0.observed_at', '2026-11-01T12:01:23+00:00')->where($prop.'.metrics', $current['metrics']));
            $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page->has($prop.'.history', 2));
            $this->getJson($path.'?period=unsupported')->assertUnprocessable()->assertJsonValidationErrors('period');
        }
    }
}
