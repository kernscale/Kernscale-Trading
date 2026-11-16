<?php

namespace App\Services;

use App\Models\IntegrationSnapshot;
use App\Models\ResearchOutput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TradingDataSync
{
    /** @return list<string> */
    public function sync(): array
    {
        $errors = [];
        foreach (['portfolio' => '/v1/portfolio', 'agents' => '/v1/status'] as $kind => $path) {
            try {
                $payload = app(ResearchCoordinator::class)->bridge()->get($path)->throw()->json();
                $this->snapshot($kind, $payload);
            } catch (\Throwable $error) {
                $errors[] = $kind.' unavailable; saved observation retained.';
            }
        }
        try {
            $response = app(ResearchCoordinator::class)->bridge()->get('/v1/reports')->throw()->json();
            $this->snapshot('native_reports', ['observed_at' => now()->toIso8601String(), 'source' => 'scheduled-native-research', 'reports' => $response['reports'] ?? []]);
            $owner = User::role('owner')->where('membership_status', 'approved')->first();
            if ($owner) {
                foreach ($response['reports'] ?? [] as $report) {
                    if (! is_string($report['id'] ?? null) || ! preg_match('/^[a-f0-9-]{36}$/i', $report['id'])) {
                        continue;
                    }
                    if (ResearchOutput::where('external_id', $report['id'])->exists()) {
                        continue;
                    }
                    if (! empty($report['has_report'])) {
                        $artifact = app(ResearchCoordinator::class)->bridge()->get('/v1/reports/'.$report['id'])->throw()->json();
                        if (($artifact['id'] ?? null) !== $report['id'] || ! is_string($artifact['content'] ?? null) || ! hash_equals($report['report_sha256'] ?? '', hash('sha256', $artifact['content']))) {
                            throw new \RuntimeException('Report checksum mismatch.');
                        }
                        $this->importReport([...$report, 'content' => $artifact['content']], $owner->id);
                    }
                }
            }
        } catch (\Throwable $error) {
            $errors[] = 'Reports unavailable; saved research retained.';
        }

        return $errors;
    }

    /** @param array<string, mixed> $payload */
    public function snapshot(string $kind, array $payload): void
    {
        $rules = ['observed_at' => 'required|date', 'source' => 'required|string|max:200'];
        if ($kind === 'portfolio') {
            foreach (['accounts', 'holdings', 'activities', 'metrics', 'history'] as $field) {
                $rules[$field] = 'present|array';
            }
        }
        Validator::make($payload, $rules)->validate();
        $observed = CarbonImmutable::parse($payload['observed_at']);
        if ($observed->gt(now()->addMinutes(5))) {
            throw new \InvalidArgumentException('Observation is in the future.');
        }
        DB::transaction(function () use ($kind, $payload, $observed) {
            $current = IntegrationSnapshot::where('kind', $kind)->lockForUpdate()->first();
            if (! $current || $current->observed_at->lte($observed)) {
                IntegrationSnapshot::updateOrCreate(['kind' => $kind], ['payload' => $payload, 'observed_at' => $observed]);
                if ($kind === 'portfolio') {
                    app(PortfolioObservations::class)->record($payload);
                }
            }
        });
    }

    /** @param array<string, mixed> $report */
    public function importReport(array $report, int $ownerId): void
    {
        Validator::make($report, ['id' => 'required|string|max:220', 'title' => 'required|string|max:200', 'content' => 'required|string', 'sources' => 'sometimes|array', 'created_at' => 'required|date'])->validate();
        $checksum = hash('sha256', $report['content']);
        // Native receipts remain immutable; human revisions belong to separate drafts.
        ResearchOutput::firstOrCreate(['external_id' => $report['id']], [
            'user_id' => $ownerId, 'title' => $report['title'], 'kind' => 'research', 'status' => 'in_review',
            'content' => $report['content'], 'sources' => $report['sources'] ?? [], 'original_checksum' => $checksum,
            'created_at' => CarbonImmutable::parse($report['created_at']),
        ]);
    }
}
