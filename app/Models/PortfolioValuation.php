<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonInterface $bucket_at
 * @property CarbonInterface $observed_at
 * @property CarbonInterface|null $market_asof
 * @property string $equity
 * @property string|null $cash
 * @property string|null $deposits
 * @property array<string, mixed> $provenance
 */
class PortfolioValuation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['bucket_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime',
            'market_asof' => 'immutable_datetime', 'equity' => 'decimal:8',
            'cash' => 'decimal:8', 'deposits' => 'decimal:8', 'provenance' => 'array'];
    }
}
