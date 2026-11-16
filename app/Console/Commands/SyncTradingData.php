<?php

namespace App\Console\Commands;

use App\Services\TradingDataSync;
use Illuminate\Console\Command;

class SyncTradingData extends Command
{
    protected $signature = 'trading:sync';

    protected $description = 'Refresh native read-only projections; retain saved data during outages';

    public function handle(TradingDataSync $sync): int
    {
        $errors = $sync->sync();
        foreach ($errors as $error) {
            $this->warn($error);
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
