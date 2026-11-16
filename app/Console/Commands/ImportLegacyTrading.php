<?php

namespace App\Console\Commands;

use App\Services\LegacyTradingImport;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ImportLegacyTrading extends Command
{
    protected $signature = 'trading:import-legacy {manifest : Protected operator JSON manifest} {--sha256= : Reviewed manifest SHA-256} {--owner= : Existing approved owner ID}';

    protected $description = 'Import explicit legacy identities/content once; retain financial exports only as archives';

    public function handle(LegacyTradingImport $import): int
    {
        try {
            $owner = filter_var($this->option('owner'), FILTER_VALIDATE_INT);
            if (! $owner || $owner < 1) {
                throw new \InvalidArgumentException('An existing owner ID is required.');
            }
            $counts = $import->import($this->argument('manifest'), (string) $this->option('sha256'), $owner);
            $this->info('Imported '.$counts['created'].' records; replayed '.$counts['replayed'].' unchanged records.');

            return self::SUCCESS;
        } catch (\InvalidArgumentException|ValidationException|\JsonException $error) {
            $this->error('Legacy import rejected: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
