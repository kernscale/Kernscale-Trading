<?php

namespace App\Http\Controllers;

use App\Models\IntegrationSnapshot;
use App\Models\ResearchOutput;
use App\Models\ResearchSession;
use App\Models\User;
use App\Services\PortfolioObservations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class HubController extends Controller
{
    public function capitalioPreview(Request $request): Response
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless($request->user()->hasRole('owner'), 403);

        return Inertia::render('hub/capitalio-preview', ['designPreview' => true]);
    }

    public function dashboard(Request $request): Response
    {
        $reports = IntegrationSnapshot::where('kind', 'native_reports')->first()?->payload['reports'] ?? [];
        $latestDecision = null;
        foreach (is_array($reports) ? $reports : [] as $report) {
            if (is_array($report) && (! empty($report['rating']) || ! empty($report['card_status']))) {
                $latestDecision = $report;
                break;
            }
        }

        return Inertia::render('hub/dashboard', [
            'portfolio' => $this->portfolioPayload(IntegrationSnapshot::where('kind', 'portfolio')->first()),
            'latestDecision' => $latestDecision,
            'agentStatus' => IntegrationSnapshot::where('kind', 'agents')->first()?->payload,
            'counts' => ['sessions' => ResearchSession::where('user_id', $request->user()->id)->count(), 'outputs' => ResearchOutput::visibleTo($request->user())->count(), 'pendingReview' => $request->user()->hasRole('owner') ? ResearchOutput::where('status', 'in_review')->count() : 0],
            'recentOutputs' => ResearchOutput::visibleTo($request->user())->with('author')->latest()->limit(5)->get(),
        ]);
    }

    public function portfolio(Request $request): Response
    {
        $snapshot = IntegrationSnapshot::where('kind', 'portfolio')->first();

        return Inertia::render('hub/portfolio', ['watchlists' => DB::table('watchlist_items')->where('user_id', $request->user()->id)->orderBy('symbol')->get(), 'snapshot' => $this->portfolioPayload($snapshot), 'freshness' => ['stale' => ! $snapshot || $snapshot->observed_at->lt(now()->subSeconds(config('trading.stale_after_seconds'))), 'observed_at' => $snapshot?->observed_at?->toIso8601String()]]);
    }

    public function admin(Request $request): Response
    {
        abort_unless($request->user()->hasRole('owner'), 403);

        return Inertia::render('hub/admin', ['users' => User::select('id', 'name', 'email', 'membership_status', 'created_at')->with('roles:id,name')->get(), 'invitations' => DB::table('invitations')->select('id', 'email', 'role', 'expires_at', 'accepted_at')->latest()->get()]);
    }

    /** @return array<string, mixed>|null */
    private function portfolioPayload(?IntegrationSnapshot $snapshot): ?array
    {
        if (! $snapshot) {
            return null;
        }
        $payload = $snapshot->payload;
        $provenance = $payload['provenance'] ?? null;
        $account = is_array($provenance) ? ($provenance['account'] ?? null) : null;
        $payload['history'] = is_string($account) ? app(PortfolioObservations::class)->history($account) : [];
        $payload['history_provenance'] = 'Observed native valuations, with original capture timestamps.';

        return $payload;
    }
}
