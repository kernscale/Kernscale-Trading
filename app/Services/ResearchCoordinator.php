<?php

namespace App\Services;

use App\Jobs\DispatchResearch;
use App\Models\ResearchMessage;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class ResearchCoordinator
{
    public function authorize(User $user, ResearchSession $session): void
    {
        abort_unless($user->membership_status === 'approved' && $user->hasAnyRole(['researcher', 'owner']), 403);
        abort_unless($session->user_id === $user->id, 404);
    }

    /** @param list<string> $attachmentIds */
    public function start(User $user, ResearchSession $session, string $prompt, string $key, array $attachmentIds = []): ResearchRun
    {
        $this->authorize($user, $session);

        return DB::transaction(function () use ($user, $session, $prompt, $key, $attachmentIds) {
            ResearchSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if($session->runs()->whereIn('status', ['queued', 'dispatching', 'waiting', 'waiting_for_input', 'running', 'collecting', 'cancel_requested'])->exists() && ! ResearchRun::where('user_id', $user->id)->where('idempotency_key', $key)->exists(), 409, 'A research turn is already active.');
            $attachments = app(ResearchAttachments::class)->snapshot($user, $session, $attachmentIds);
            $run = ResearchRun::firstOrCreate(['user_id' => $user->id, 'idempotency_key' => $key], [
                'research_session_id' => $session->id, 'prompt' => $prompt, 'status' => 'queued', 'attachments' => $attachments, 'workspace' => app(WorkspaceProposals::class)->captureFor($session),
            ]);
            abort_unless($run->research_session_id === $session->id && hash_equals(hash('sha256', $run->prompt), hash('sha256', $prompt)), 409, 'Request key already used for different work.');
            abort_unless(($run->attachments ?? []) === $attachments, 409, 'Request key already used for different attachments.');
            if ($run->wasRecentlyCreated) {
                ResearchMessage::create(['research_session_id' => $session->id, 'research_run_id' => $run->id, 'role' => 'user', 'content' => $prompt]);
                $session->touch();
                DispatchResearch::dispatch($run->id)->afterCommit();
            }

            return $run;
        });
    }

    public function bridge(): PendingRequest
    {
        if (! config('trading.bridge_url') || ! config('trading.bridge_token')) {
            throw new \RuntimeException('Research bridge is not configured.');
        }

        return Http::baseUrl(rtrim(config('trading.bridge_url'), '/'))
            ->withToken(config('trading.bridge_token'))->withHeaders(['X-Workspace-ID' => 'kernscale-trading'])
            ->acceptJson()->connectTimeout(3)->timeout(15);
    }

    private function path(ResearchRun $run): string
    {
        return '/v1/researchers/'.$run->user_id.'/runs/'.$run->id;
    }

    /**
     * @param  list<array{id: string, answer: string}>  $answers
     * @return array<string, mixed>
     */
    public function answer(User $user, ResearchRun $run, string $requestId, array $answers): array
    {
        $run->refresh();
        $this->authorize($user->fresh(), $run->session);
        abort_unless($run->user_id === $user->id, 404);
        $response = $this->bridge()->post($this->path($run).'/clarifications/'.$requestId.'/answer', ['answers' => $answers])->throw()->json();
        abort_unless(is_array($response), 502, 'Invalid clarification receipt.');
        $validator = Validator::make($response, [
            'id' => 'required|uuid', 'status' => 'required|in:queued,dispatching,waiting,waiting_for_input,running,collecting,completed,cancel_requested,cancelled,failed,interrupted',
            'clarification' => 'required|array', 'clarification.request_id' => 'required|uuid',
            'clarification.status' => 'required|in:pending,accepted,consumed,expired,cancelled,interrupted',
            'clarification.expires_at' => 'required|date',
            'clarification.answered_at' => 'present|nullable|date', 'clarification.consumed_at' => 'present|nullable|date',
        ]);
        abort_if($validator->fails() || ($response['id'] ?? null) !== $run->id || ($response['clarification']['request_id'] ?? null) !== $requestId, 502, 'Invalid clarification receipt.');
        $clarification = array_intersect_key($response['clarification'], array_flip(['request_id', 'status', 'expires_at', 'answered_at', 'consumed_at']));
        foreach (['expires_at', 'answered_at', 'consumed_at'] as $field) {
            abort_if($clarification[$field] !== null && (! is_string($clarification[$field]) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $clarification[$field])), 502, 'Invalid clarification receipt.');
        }
        // Metadata-only terminal receipts must not seal partial text or skip final events.
        $status = in_array($response['status'], ['completed', 'cancelled', 'failed', 'interrupted'], true) ? 'collecting' : $response['status'];
        $this->ingest($run, ['status' => $status]);

        return ['id' => $run->id, 'status' => $run->fresh()->status, 'clarification' => $clarification];
    }

    public function dispatch(ResearchRun $run): void
    {
        $run->refresh();
        $user = $run->user->fresh();
        if ($user->membership_status !== 'approved' || ! $user->hasAnyRole(['researcher', 'owner'])) {
            $this->cancel($run);

            return;
        }
        $claimed = ResearchRun::whereKey($run->id)->whereIn('status', ['queued', 'dispatching', 'interrupted'])->update(['status' => 'dispatching', 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $run->refresh();
        $user = $run->user->fresh();
        if ($run->status !== 'dispatching') {
            return;
        }
        if ($user->membership_status !== 'approved' || ! $user->hasAnyRole(['researcher', 'owner'])) {
            $this->cancel($run);

            return;
        }
        $response = $this->bridge()->put($this->path($run), [
            'workspace_id' => 'kernscale-trading', 'tenant_id' => 'kernscale-trading',
            'attachments' => $run->attachments ?? [],
            'session_id' => $run->research_session_id, 'prompt' => $run->prompt, 'model' => config('trading.model'), 'workspace' => $run->workspace ?? app(WorkspaceProposals::class)->captureFor($run->session),
        ])->throw()->json();
        $this->ingest($run, $response);
        if ($run->fresh()->status === 'cancel_requested') {
            $this->cancel($run->fresh());
        }
    }

    public function sync(ResearchRun $run): void
    {
        $run->refresh();
        if (! in_array($run->status, ['dispatching', 'waiting', 'waiting_for_input', 'running', 'collecting', 'cancel_requested', 'interrupted'], true)) {
            return;
        }
        $user = $run->user->fresh();
        if ($user->membership_status !== 'approved' || ! $user->hasAnyRole(['researcher', 'owner'])) {
            $this->cancel($run);

            return;
        }
        $after = $run->events()->max('remote_id') ?? 0;
        $response = $this->bridge()->get($this->path($run).'/events', ['after' => $after])->throw()->json();
        $this->ingest($run, $response);
    }

    public function cancel(ResearchRun $run): void
    {
        $cancelled = ResearchRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'cancelled', 'completed_at' => now(), 'updated_at' => now()]);
        if ($cancelled === 1) {
            $run->refresh();

            return;
        }
        $requested = ResearchRun::whereKey($run->id)->whereNotIn('status', ['completed', 'cancelled', 'failed'])->update(['status' => 'cancel_requested', 'updated_at' => now()]);
        $run->refresh();
        if ($requested !== 1 || in_array($run->status, ['completed', 'cancelled', 'failed'], true)) {
            return;
        }
        $response = $this->bridge()->post($this->path($run).'/cancel')->throw()->json();
        $this->ingest($run, $response);
    }

    /** @param array<string, mixed> $response */
    public function ingest(ResearchRun $run, array $response): void
    {
        DB::transaction(function () use ($run, $response) {
            $run = ResearchRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            foreach ($response['events'] ?? [] as $event) {
                if (! is_int($event['id'] ?? null) || ! is_string($event['type'] ?? null) || ! is_array($event['payload'] ?? null)) {
                    continue;
                }
                $stored = $run->events()->firstOrCreate(['remote_id' => $event['id']], ['type' => $event['type'], 'payload' => $event['payload']]);
                if ($stored->wasRecentlyCreated) {
                    app(WorkspaceProposals::class)->ingest($run, $event);
                }
            }
            $status = $response['status'] ?? $run->status;
            if ($status === 'queued' && $run->status !== 'queued') {
                $status = 'waiting';
            }
            if (($response['has_more'] ?? false) && in_array($status, ['completed', 'cancelled', 'failed', 'interrupted'], true)) {
                $status = 'collecting';
            }
            if (! in_array($status, ['queued', 'dispatching', 'waiting', 'waiting_for_input', 'running', 'collecting', 'completed', 'cancel_requested', 'cancelled', 'failed', 'interrupted'], true)) {
                $status = 'interrupted';
            }
            // A late status poll must not undo a confirmed terminal result or pending cancellation.
            if (in_array($run->status, ['completed', 'cancelled', 'failed'], true)) {
                $status = $run->status;
            }
            if ($run->status === 'cancel_requested' && in_array($status, ['queued', 'waiting', 'waiting_for_input', 'running', 'dispatching', 'collecting'], true)) {
                $status = 'cancel_requested';
            }
            $run->status = $status;
            if ($status === 'running') {
                $run->started_at ??= now();
            }
            if (in_array($status, ['completed', 'cancelled', 'failed'], true)) {
                $run->completed_at ??= now();
            }
            $run->save();
            if ($status === 'completed') {
                $final = $run->events()->where('type', 'completed')->latest('remote_id')->first()?->payload['text'] ?? null;
                $text = $response['output'] ?? $final ?? $run->events()->get()->filter(fn ($event) => $event->type === 'token')->map(fn ($event) => $event->payload['text'] ?? '')->implode('');
                if (is_string($text) && $text !== '') {
                    ResearchMessage::firstOrCreate(['research_run_id' => $run->id, 'role' => 'assistant'], ['research_session_id' => $run->research_session_id, 'content' => $text]);
                }
            }
        });
    }
}
