<?php

namespace App\Services;

use App\Models\BoardCard;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use App\Models\WorkspaceProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WorkspaceProposals
{
    /** @return array{thesis: array{content: string, version: int}, board: array<int, mixed>} */
    public function captureFor(ResearchSession $session): array
    {
        $session = $session->fresh();

        return ['thesis' => ['content' => $session->thesis, 'version' => $session->thesis_version],
            'board' => $session->board()->get(['id', 'title', 'content', 'status', 'version'])->toArray()];
    }

    /** @param array<string, mixed> $event */
    public function ingest(ResearchRun $run, array $event): ?WorkspaceProposal
    {
        if (($event['type'] ?? null) !== 'workspace_proposal' || ! is_int($event['id'] ?? null) || $event['id'] < 1 || ! is_array($event['payload'] ?? null)) {
            return null;
        }
        $run = $run->fresh();
        $session = $run?->session;
        if (! $run || ! $session || $run->user_id !== $session->user_id) {
            return null;
        }
        $payload = $event['payload'];
        if (! $this->validPayload($payload)) {
            return null;
        }
        if ($payload['operation'] === 'update_card' && ! BoardCard::whereKey($payload['card_id'])->where('research_session_id', $session->id)->exists()) {
            return null;
        }
        if (array_key_exists('content', $payload)) {
            $payload['content'] ??= '';
        }

        return WorkspaceProposal::firstOrCreate(['run_id' => $run->id, 'remote_event_id' => $event['id']], [
            'session_id' => $session->id, 'user_id' => $run->user_id,
            'operation' => $payload['operation'], 'payload' => $payload, 'status' => 'pending',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function validPayload(array $payload): bool
    {
        $operation = $payload['operation'] ?? null;
        if (! in_array($operation, ['create_card', 'update_card', 'update_thesis'], true)) {
            return false;
        }
        $fields = match ($operation) {
            'create_card' => 'operation,title,content,status',
            'update_card' => 'operation,card_id,expected_version,title,content,status',
            'update_thesis' => 'operation,content,expected_version',
        };
        $rules = ['payload' => 'required|array:'.$fields];
        if ($operation !== 'create_card') {
            if (! is_int($payload['expected_version'] ?? null)) {
                return false;
            }
            $rules['payload.expected_version'] = 'required|integer|min:1';
        }
        if ($operation === 'update_thesis') {
            $rules['payload.content'] = 'present|nullable|string|max:50000';
        } else {
            $rules['payload.title'] = ($operation === 'create_card' ? 'required' : 'sometimes').'|string|max:160';
            $rules['payload.content'] = 'sometimes|nullable|string|max:50000';
            $rules['payload.status'] = 'sometimes|in:backlog,researching,review,done';
            if ($operation === 'update_card') {
                $rules['payload.card_id'] = 'required|uuid';
                if (array_intersect(['title', 'content', 'status'], array_keys($payload)) === []) {
                    return false;
                }
            }
        }

        return ! Validator::make(['payload' => $payload], $rules)->fails();
    }

    public function apply(User $user, WorkspaceProposal $proposal, string $decision): WorkspaceProposal
    {
        abort_unless(in_array($decision, ['apply', 'reject'], true), 422);

        return DB::transaction(function () use ($user, $proposal, $decision) {
            $proposal = WorkspaceProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            $session = ResearchSession::whereKey($proposal->session_id)->lockForUpdate()->firstOrFail();
            app(ResearchCoordinator::class)->authorize($user->fresh(), $session);
            abort_unless($proposal->user_id === $session->user_id, 404);
            $status = $decision === 'apply' ? 'applied' : 'rejected';
            if ($proposal->status !== 'pending') {
                abort_unless($proposal->status === $status, 409, 'This proposal already has a different decision.');

                return $proposal;
            }
            if ($decision === 'apply') {
                $payload = $proposal->payload;
                abort_unless($this->validPayload($payload) && $payload['operation'] === $proposal->operation, 422);
                if ($proposal->operation === 'create_card') {
                    BoardCard::create(['research_session_id' => $session->id, 'title' => $payload['title'],
                        'content' => $payload['content'] ?? '', 'status' => $payload['status'] ?? 'backlog']);
                } elseif ($proposal->operation === 'update_thesis') {
                    abort_unless($session->thesis_version === $payload['expected_version'], 409, 'The thesis changed. Review a new proposal.');
                    $session->update(['thesis' => $payload['content'] ?? '', 'thesis_version' => $session->thesis_version + 1]);
                } else {
                    $card = BoardCard::whereKey($payload['card_id'])->where('research_session_id', $session->id)->lockForUpdate()->firstOrFail();
                    abort_unless($card->version === $payload['expected_version'], 409, 'The card changed. Review a new proposal.');
                    $card->update([...array_intersect_key($payload, array_flip(['title', 'content', 'status'])), 'version' => $card->version + 1]);
                }
                $session->touch();
            }
            $proposal->update(['status' => $status]);

            return $proposal;
        });
    }
}
