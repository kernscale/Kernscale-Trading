<?php

namespace App\Services;

use App\Models\ResearchMessage;
use App\Models\ResearchOutput;
use App\Models\ResearchRun;
use App\Models\ResearchSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResearchArtifacts
{
    /** @return array{ResearchOutput, bool} */
    public function derive(User $user, ResearchOutput $source, string $title, string $kind, int $version, string $key, ?string $sessionId): array
    {
        return DB::transaction(function () use ($user, $source, $title, $kind, $version, $key, $sessionId) {
            $user = $user->fresh();
            abort_unless($user->membership_status === 'approved' && $user->hasAnyRole(['researcher', 'owner']), 403);
            $source = ResearchOutput::visibleTo($user)->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($sessionId !== null) {
                ResearchSession::where('user_id', $user->id)->findOrFail($sessionId);
            }
            $creation = ['source_output_id' => $source->id, 'source_output_version' => $version,
                'title' => $title, 'kind' => $kind, 'session_id' => $sessionId];
            $existing = ResearchOutput::where('user_id', $user->id)->where('derivation_key', $key)->first();
            if ($existing) {
                abort_unless(($existing->provenance['creation'] ?? null) === $creation, 409, 'This derivation key already identifies different work.');

                return [$existing, false];
            }
            abort_unless($source->version === $version, 409, 'Review the current source revision before deriving a draft.');
            $checksum = hash('sha256', $source->content);
            $draft = ResearchOutput::firstOrCreate(['user_id' => $user->id, 'derivation_key' => $key], [
                'source_output_id' => $source->id, 'source_output_version' => $version,
                'research_session_id' => $sessionId, 'research_run_id' => null, 'external_id' => null,
                'title' => $title, 'kind' => $kind, 'status' => 'draft', 'content' => $source->content,
                'sources' => $source->sources, 'original_checksum' => $checksum,
                'provenance' => ['schema_version' => 1, 'source' => 'derived_output', 'creation' => $creation,
                    'source_output_id' => $source->id, 'source_output_version' => $version,
                    'source_content_sha256' => $checksum, 'source_original_checksum' => $source->original_checksum,
                    'inherited_source_references' => $this->inheritedReferences($source, $user)],
            ]);
            abort_unless(($draft->provenance['creation'] ?? null) === $creation, 409, 'This derivation key already identifies different work.');
            if ($draft->wasRecentlyCreated) {
                DB::table('audit_entries')->insert(['user_id' => $user->id, 'action' => 'output.derived', 'subject_id' => $draft->id,
                    'metadata' => json_encode(['source_output_id' => $source->id, 'source_output_version' => $version], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now()]);
            }

            return [$draft, $draft->wasRecentlyCreated];
        });
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function inheritedReferences(ResearchOutput $source, User $user): array
    {
        $provenance = $source->provenance;
        if ($source->user_id !== $user->id || ! is_array($provenance)) {
            return [];
        }
        $result = [];
        foreach (['evidence', 'admitted_attachments', 'attachment_references'] as $group) {
            foreach (is_array($provenance[$group] ?? null) ? $provenance[$group] : [] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $safe = [];
                foreach ($row as $field => $value) {
                    if (in_array($field, ['event_id', 'remote_event_id', 'size'], true) && is_int($value) && $value > 0) {
                        $safe[$field] = $value;
                    } elseif (in_array($field, ['sha256', 'extracted_text_sha256', 'event_payload_sha256'], true) && is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value)) {
                        $safe[$field] = $value;
                    } elseif ($field === 'id' && is_string($value) && Str::isUuid($value)) {
                        $safe[$field] = $value;
                    } elseif ($field === 'evidence_id' && is_string($value) && preg_match('/^e[1-9][0-9]*$/D', $value)) {
                        $safe[$field] = $value;
                    } elseif ($field === 'inputs' && is_array($value)) {
                        $safe[$field] = array_values(array_filter($value, fn ($id) => is_string($id) && preg_match('/^e[1-9][0-9]*$/D', $id)));
                    } elseif ($field === 'media_type' && in_array($value, ['application/pdf', 'text/plain', 'text/markdown', 'text/csv'], true)) {
                        $safe[$field] = $value;
                    } elseif (in_array($field, ['name', 'tool'], true) && is_string($value) && mb_strlen($value) <= 255 && ! preg_match('/[\x00-\x1F\x7F\/\\\\]/', $value)) {
                        $safe[$field] = $value;
                    }
                }
                if ($safe !== []) {
                    $result[$group][] = $safe;
                }
            }
        }

        return $result;
    }

    /** @return array{ResearchOutput, bool} */
    public function save(User $user, ResearchRun $run, string $title, string $kind): array
    {
        return DB::transaction(function () use ($user, $run, $title, $kind) {
            $run = ResearchRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            app(ResearchCoordinator::class)->authorize($user->fresh(), $run->session);
            abort_unless($run->user_id === $user->id, 404);
            abort_unless($run->status === 'completed', 409, 'Only completed research can become an artifact.');
            $existing = ResearchOutput::where('research_run_id', $run->id)->first();
            if ($existing) {
                abort_unless($existing->user_id === $user->id && $existing->research_session_id === $run->research_session_id, 404);
                $provenance = $existing->provenance;
                abort_unless(is_array($provenance) && ($provenance['origin_title'] ?? null) === $title && ($provenance['origin_kind'] ?? null) === $kind, 409, 'This run already has a canonical artifact with different creation metadata.');

                return [$existing, false];
            }
            $message = ResearchMessage::where('research_run_id', $run->id)->where('research_session_id', $run->research_session_id)->where('role', 'assistant')->first();
            abort_unless($message && trim($message->content) !== '', 409, 'Canonical assistant text is unavailable.');
            [$sources, $evidence] = $this->references($run);
            [$admittedAttachments, $attachmentReferences] = $this->attachments($run);
            $checksum = hash('sha256', $message->content);
            $output = ResearchOutput::create(['user_id' => $user->id, 'research_session_id' => $run->research_session_id,
                'research_run_id' => $run->id, 'title' => $title, 'kind' => $kind, 'status' => 'draft',
                'content' => $message->content, 'sources' => $sources, 'original_checksum' => $checksum,
                'provenance' => ['schema_version' => 1, 'source' => 'saved_interactive_research',
                    'run_id' => $run->id, 'session_id' => $run->research_session_id, 'assistant_message_id' => $message->id,
                    'content_sha256' => $checksum, 'origin_title' => $title, 'origin_kind' => $kind, 'evidence' => $evidence,
                    'admitted_attachments' => $admittedAttachments, 'attachment_references' => $attachmentReferences]]);
            DB::table('audit_entries')->insert(['user_id' => $user->id, 'action' => 'output.created_from_run',
                'subject_id' => $output->id, 'metadata' => json_encode(['run_id' => $run->id], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return [$output, true];
        });
    }

    /** @return array{array<int, array<string, mixed>>, array<int, array<string, mixed>>} */
    private function attachments(ResearchRun $run): array
    {
        $admitted = [];
        $snapshot = $run->getAttribute('attachments');
        foreach (is_array($snapshot) ? $snapshot : [] as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null) || ! Str::isUuid($item['id'])
                || ! is_string($item['name'] ?? null) || $item['name'] === '' || mb_strlen($item['name']) > 255
                || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $item['name'])
                || ! in_array($item['media_type'] ?? null, ['application/pdf', 'text/plain', 'text/markdown', 'text/csv'], true)
                || ! is_int($item['size'] ?? null) || $item['size'] < 1
                || ! is_string($item['sha256'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $item['sha256'])
                || ! is_string($item['text'] ?? null) || trim($item['text']) === '') {
                continue;
            }
            $admitted[$item['id']] = ['id' => $item['id'], 'name' => $item['name'], 'media_type' => $item['media_type'],
                'size' => $item['size'], 'sha256' => $item['sha256'], 'extracted_text_sha256' => hash('sha256', $item['text'])];
        }
        $references = [];
        foreach ($run->events()->where('type', 'attachment_reference')->get() as $event) {
            $payload = $event->payload;
            $id = $payload['id'] ?? null;
            $originalHash = $payload['sha256'] ?? null;
            if (! is_string($id) || ! isset($admitted[$id]) || ! is_string($originalHash)
                || ! hash_equals($admitted[$id]['sha256'], $originalHash)) {
                continue;
            }
            $item = $admitted[$id];
            if (array_key_exists('extracted_text_sha256', $payload) && $payload['extracted_text_sha256'] !== $item['extracted_text_sha256']) {
                continue;
            }
            $references[] = [...$item, 'event_id' => $event->id, 'remote_event_id' => $event->remote_id,
                'event_payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
        }

        return [array_values($admitted), $references];
    }

    /** @return array{array<int, array<string, mixed>>, array<int, array<string, mixed>>} */
    private function references(ResearchRun $run): array
    {
        $sources = [];
        $evidence = [];
        foreach ($run->events()->whereIn('type', ['record', 'source'])->get() as $event) {
            $payload = $event->payload;
            $reference = ['event_id' => $event->id, 'remote_event_id' => $event->remote_id];
            $id = is_string($payload['id'] ?? null) && preg_match('/^e[1-9][0-9]*$/D', $payload['id']) ? $payload['id'] : null;
            if ($event->type === 'record' && $id !== null) {
                $record = [...$reference, 'evidence_id' => $id,
                    'event_payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
                if (is_string($payload['tool'] ?? null) && preg_match('/^[a-zA-Z0-9_.-]{1,120}$/D', $payload['tool'])) {
                    $record['tool'] = $payload['tool'];
                }
                $record['inputs'] = array_values(array_filter(is_array($payload['inputs'] ?? null) ? $payload['inputs'] : [], fn ($input) => is_string($input) && preg_match('/^e[1-9][0-9]*$/D', $input)));
                $evidence[] = $record;
            }
            $rows = $event->type === 'source' ? [$payload] : [];
            $data = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
            foreach (['sources', 'pages', 'results'] as $key) {
                if (is_array($data[$key] ?? null)) {
                    array_push($rows, ...array_filter($data[$key], 'is_array'));
                }
            }
            foreach ($rows as $row) {
                $url = $row['url'] ?? null;
                if (! $this->safeUrl($url)) {
                    continue;
                }
                $source = [...$reference, 'url' => $url];
                if ($id !== null) {
                    $source['evidence_id'] = $id;
                }
                if (is_string($row['title'] ?? null) && mb_strlen($row['title']) <= 250 && ! preg_match('/[\x00-\x1F\x7F]/', $row['title'])) {
                    $source['title'] = $row['title'];
                }
                $index = $row['citation_index'] ?? ($event->type === 'source' ? ($row['index'] ?? null) : null);
                if (is_int($index) && $index > 0) {
                    $source['citation_index'] = $index;
                }
                $key = $url.'|'.($source['citation_index'] ?? '');
                $sources[$key] = array_replace($sources[$key] ?? [], $source);
            }
        }

        return [array_values($sources), $evidence];
    }

    private function safeUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        return in_array($parts['scheme'] ?? '', ['http', 'https'], true) && ! isset($parts['user']) && ! isset($parts['pass'])
            && $host !== 'localhost' && ! str_ends_with($host, '.localhost') && ! str_ends_with($host, '.local') && ! str_ends_with($host, '.internal')
            && (! filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
    }
}
