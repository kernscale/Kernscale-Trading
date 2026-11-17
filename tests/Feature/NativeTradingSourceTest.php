<?php

namespace Tests\Feature;

use App\Services\NativeTradingSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NativeTradingSourceTest extends TestCase
{
    private const ORIGIN = 'https://trading.tail84e234.ts.net';

    private const ID = '12345678-1234-1234-1234-123456789abc';

    protected function setUp(): void
    {
        parent::setUp();
        config(['trading.native_url' => self::ORIGIN, 'trading.native_account' => 'admitted-paper',
            'trading.native_account_fingerprint' => hash('sha256', 'admitted-paper'),
            'trading.bridge_token' => 'never-forward-this']);
        Http::preventStrayRequests();
    }

    /** @return array<string, mixed> */
    private function listData(): array
    {
        return ['account' => 'admitted-paper', 'total' => 2, 'runs' => [
            ['id' => self::ID, 'status' => 'complete', 'symbol' => 'MSFT', 'started_at' => 1794917427.9261317,
                'completed_at' => 1794918371.9698014, 'card_status' => 'complete', 'has_report' => true],
            ['id' => '12345678-1234-1234-1234-123456789abd', 'status' => 'failed', 'started_at' => 1794903014,
                'completed_at' => 1794903069, 'has_report' => false],
        ]];
    }

    public function test_literal_scope_is_required_before_any_request(): void
    {
        foreach (['trading.native_url' => 'https://attacker.invalid', 'trading.native_account' => 'other',
            'trading.native_account_fingerprint' => 'bad'] as $key => $value) {
            $before = config($key);
            config([$key => $value]);
            $source = new NativeTradingSource;
            $this->assertFalse($source->configured());
            try {
                $source->reports();
                $this->fail('Unadmitted scope was accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('not admitted', $error->getMessage());
            }
            config([$key => $before]);
        }
        Http::assertNothingSent();
    }

    public function test_failed_rows_and_actual_response_hashes_are_preserved_without_bulk_artifact_downloads(): void
    {
        $body = json_encode(['ok' => true, 'data' => $this->listData()], JSON_THROW_ON_ERROR);
        Http::fake([self::ORIGIN.'/api/research*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
        $rows = (new NativeTradingSource)->reports();
        $this->assertSame(['complete', 'failed'], array_column($rows, 'status'));
        $this->assertFalse($rows[1]['has_report']);
        $this->assertNull($rows[0]['report_sha256']);
        $this->assertSame(hash('sha256', $body), $rows[0]['metadata_response_sha256']);
        $this->assertSame(1794917427.9261317, $rows[0]['started_at']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request['account'] === 'admitted-paper' && $request['limit'] === 500
            && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-Workspace-ID')
            && ! $request->hasHeader('Cookie'));
    }

    public function test_artifact_uses_account_verified_native_uuid_and_actual_html_bytes(): void
    {
        $html = '<html><body><h1>Actual saved research</h1></body></html>';
        Http::fake([
            self::ORIGIN.'/api/research?*' => Http::response(['ok' => true, 'data' => $this->listData()]),
            self::ORIGIN.'/api/research/'.self::ID.'/report?*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);
        $source = new NativeTradingSource;
        $source->reports();
        $artifact = $source->reportArtifact(self::ID);
        $this->assertSame($html, $artifact['content']);
        $this->assertSame(hash('sha256', $html), $artifact['sha256']);
        $this->assertSame('text/html', $artifact['media_type']);
        $this->assertSame('admitted-paper', $artifact['account']);
        Http::assertSentCount(2);
    }

    public function test_status_preserves_source_time_and_idle_timer_without_financial_inference(): void
    {
        Http::fake([self::ORIGIN.'/api/operations*' => Http::response(['ok' => true, 'data' => [
            'account' => 'admitted-paper', 'observed_at' => 1794917427.25, 'enabled' => true, 'worker_active' => false,
            'timer' => ['state' => 'active', 'next_run' => 'Tue 2026-11-17 14:10:19 UTC'],
            'latest_run' => ['status' => 'failed', 'reason' => 'private native diagnostic'],
        ]])]);
        $status = (new NativeTradingSource)->status();
        $this->assertSame('2026-11-17T12:10:27.250000+00:00', $status['observed_at']);
        $this->assertSame('2026-11-17T14:10:19+00:00', $status['schedule']['next_run']);
        $this->assertFalse($status['worker_active']);
        $this->assertSame('failed', $status['latest_run']['status']);
        $this->assertArrayNotHasKey('metrics', $status);
        $this->assertArrayNotHasKey('reason', $status['latest_run']);
    }

    public function test_request_options_are_bounded_and_never_follow_redirects(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['stream']);
            $this->assertSame(2, $options['connect_timeout']);
            $this->assertSame(5, $options['timeout']);
            $this->assertSame(5, $options['read_timeout']);
            $this->assertFalse($request->hasHeader('Authorization'));

            return Http::response(['ok' => true, 'data' => $this->listData()]);
        });
        (new NativeTradingSource)->reports();
    }

    public function test_wrong_returned_account_redirect_and_failure_are_rejected(): void
    {
        Http::fake([self::ORIGIN.'/*' => Http::sequence()
            ->push(['ok' => true, 'data' => ['account' => 'another']], 200)
            ->push('', 302, ['Location' => 'https://attacker.invalid'])
            ->push('', 503)]);
        foreach (range(1, 3) as $scenario) {
            try {
                (new NativeTradingSource)->reports();
                $this->fail('Invalid native response accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_forged_or_other_account_report_identity_cannot_fetch_artifacts(): void
    {
        Http::fake([self::ORIGIN.'/*' => Http::response(['ok' => true, 'data' => $this->listData()])]);
        $source = new NativeTradingSource;
        foreach (['../secret', self::ID.'/../../secret', '12345678-1234-1234-1234-123456789aaa'] as $id) {
            try {
                $source->reportArtifact($id);
                $this->fail('Unknown report identity was accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
        Http::assertSentCount(1);
    }

    public function test_wrong_content_type_and_oversized_artifact_are_rejected(): void
    {
        $headers = [];
        Http::fake(function (Request $request) use (&$headers) {
            return str_contains($request->url(), '/report?')
                ? Http::response(str_repeat('x', 2_000_001), 200, $headers)
                : Http::response(['ok' => true, 'data' => $this->listData()]);
        });
        foreach ([['Content-Type' => 'application/json'], ['Content-Type' => 'text/html', 'Content-Length' => '2000001'],
            ['Content-Type' => 'text/html']] as $headers) {
            try {
                (new NativeTradingSource)->reportArtifact(self::ID);
                $this->fail('Invalid artifact was accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }
}
