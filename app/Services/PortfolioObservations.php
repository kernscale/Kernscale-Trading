<?php

namespace App\Services;

use App\Models\PortfolioValuation;
use Carbon\CarbonImmutable;

class PortfolioObservations
{
    /** @param array<string, mixed> $payload */
    public function record(array $payload): void
    {
        $provenance = $payload['provenance'] ?? null;
        $freshness = $payload['freshness'] ?? null;
        if (! is_array($provenance) || ! is_array($freshness)) {
            return;
        }
        $account = $provenance['account'] ?? null;
        $metrics = $payload['metrics'] ?? null;
        if (($payload['source'] ?? null) !== 'native-paper-ledger' || ($freshness['stale'] ?? null) !== false
            || ! is_string($account) || trim($account) === '' || strlen($account) > 100 || preg_match('/[\x00-\x1F\x7F]/', $account)
            || ! is_array($metrics) || ! $this->validMoney($metrics['equity'] ?? null) || ! is_string($payload['observed_at'] ?? null)) {
            return;
        }
        foreach (['cash', 'deposits'] as $key) {
            if (isset($metrics[$key]) && ! $this->validMoney($metrics[$key])) {
                return;
            }
        }
        $observed = $this->observationTime($payload['observed_at']);
        $rawMarket = $freshness['market_asof'] ?? null;
        if ($rawMarket !== null && ! is_string($rawMarket)) {
            return;
        }
        $market = $rawMarket !== null ? $this->observationTime($rawMarket) : null;
        if ($observed === null || ($rawMarket !== null && $market === null) || $observed->isFuture() || $market?->gt($observed)) {
            return;
        }
        $values = ['observed_at' => $observed, 'market_asof' => $market,
            'equity' => $metrics['equity'], 'cash' => $metrics['cash'] ?? null, 'deposits' => $metrics['deposits'] ?? null,
            'source' => 'native-paper-ledger',
            'provenance' => ['native_source' => 'native-paper-ledger', 'account' => $account]];
        $digest = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
        $bucket = $observed->startOfMinute()->subMinutes($observed->minute % 5);
        PortfolioValuation::firstOrCreate(['account' => $account, 'bucket_at' => $bucket], [...$values, 'digest' => $digest]);
    }

    private function observationTime(string $value): ?CarbonImmutable
    {
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            return null;
        }
        try {
            $parsed = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                return null;
            }

            return CarbonImmutable::instance($parsed)->utc();
        } catch (\Exception $error) {
            return null;
        }
    }

    private function validMoney(mixed $value): bool
    {
        // decimal(32, 8) preserves the source amount within its explicit storage range.
        return (is_int($value) || is_float($value)) && is_finite($value) && abs($value) < 1e24;
    }

    /** @return list<array{observed_at: string, equity: string, source: string, market_asof: string|null}> */
    public function history(string $account, string $period = 'monthly'): array
    {
        $days = match ($period) {
            'weekly' => 7, 'monthly' => 30, 'quarterly' => 90, 'yearly' => 365,
            default => throw new \InvalidArgumentException('Unsupported observation history period.'),
        };
        $query = PortfolioValuation::where('account', $account)->where('source', 'native-paper-ledger');
        $latest = (clone $query)->latest('observed_at')->first();
        if ($latest === null) {
            return [];
        }
        $anchor = $latest->observed_at->toImmutable()->utc();
        $bins = [];
        $first = $latest;
        foreach ($query->whereBetween('observed_at', [$anchor->subDays($days), $anchor])
            ->select(['id', 'observed_at', 'equity', 'source', 'market_asof'])->lazyById(1000) as $valuation) {
            $key = $valuation->observed_at->utc()->format(in_array($period, ['weekly', 'monthly'], true) ? 'Y-m-d-H' : 'Y-m-d');
            if (! isset($bins[$key]) || $valuation->observed_at->gt($bins[$key]->observed_at)) {
                $bins[$key] = $valuation;
            }
            if ($valuation->observed_at->lt($first->observed_at)) {
                $first = $valuation;
            }
        }
        $selected = [];
        foreach ([...array_values($bins), $first, $latest] as $valuation) {
            $selected[$valuation->id] = $valuation;
        }
        usort($selected, fn (PortfolioValuation $left, PortfolioValuation $right) => $left->observed_at <=> $right->observed_at);

        return array_map(fn (PortfolioValuation $valuation) => [
            'observed_at' => $valuation->observed_at->toIso8601String(),
            'equity' => $valuation->equity,
            'source' => $valuation->source,
            'market_asof' => $valuation->market_asof?->toIso8601String(),
        ], $selected);
    }
}
