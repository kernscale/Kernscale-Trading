<?php

namespace App\Jobs;

use App\Models\ResearchRun;
use App\Services\ResearchCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchResearch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public string $runId)
    {
        $this->onQueue('research');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ResearchCoordinator $coordinator): void
    {
        $run = ResearchRun::find($this->runId);
        if ($run) {
            $coordinator->dispatch($run);
        }
    }

    public function failed(?\Throwable $error): void
    {
        ResearchRun::whereKey($this->runId)->whereIn('status', ['queued', 'dispatching'])->update(['status' => 'interrupted', 'error' => 'Research dispatch needs reconciliation.']);
    }
}
