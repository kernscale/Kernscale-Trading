<?php

namespace App\Console\Commands;

use App\Jobs\DispatchResearch;
use App\Models\ResearchRun;
use App\Services\ResearchCoordinator;
use Illuminate\Console\Command;

class ReconcileResearch extends Command
{
    protected $signature = 'trading:reconcile';

    protected $description = 'Reconcile durable research jobs without starting fresh duplicate runs';

    public function handle(ResearchCoordinator $service): int
    {
        $failures = 0;
        ResearchRun::whereIn('status', ['queued', 'dispatching', 'waiting', 'waiting_for_input', 'running', 'collecting', 'cancel_requested', 'interrupted'])->eachById(function ($run) use ($service, &$failures) {
            try {
                if ($run->status === 'queued') {
                    DispatchResearch::dispatch($run->id);
                } elseif ($run->status === 'cancel_requested') {
                    $service->cancel($run);
                } else {
                    $service->sync($run);
                }
            } catch (\Throwable $error) {
                $failures++;
            }
        });
        if ($failures) {
            $this->warn($failures.' runs await backend reconciliation.');
        }

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
