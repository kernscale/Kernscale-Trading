<?php

namespace App\Services;

use App\Models\CommunityComment;
use App\Models\CommunityTopic;
use App\Models\ResearchOutput;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class LegacyTradingImport
{
    /** @return array{created: int, replayed: int} */
    public function import(string $path, string $checksum, int $ownerId): array
    {
        $owner = User::find($ownerId);
        if (! $owner || $owner->membership_status !== 'approved' || ! $owner->hasRole('owner')) {
            throw new InvalidArgumentException('An approved existing owner must authorize the import.');
        }
        if (! is_file($path) || is_link($path) || filesize($path) > 20_000_000) {
            throw new InvalidArgumentException('A regular manifest file of at most 20 MB is required.');
        }
        $raw = file_get_contents($path, false, null, 0, 20_000_001);
        if ($raw === false || strlen($raw) > 20_000_000 || ! preg_match('/\A[a-f0-9]{64}\z/', $checksum) || ! hash_equals($checksum, hash('sha256', $raw))) {
            throw new InvalidArgumentException('Manifest checksum mismatch.');
        }
        $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $this->validate($manifest);

        return DB::transaction(function () use ($manifest, $checksum, $owner) {
            $owner->refresh();
            if ($owner->membership_status !== 'approved' || ! $owner->hasRole('owner')) {
                throw new InvalidArgumentException('Import owner approval was revoked.');
            }
            foreach ($manifest['identities'] as $userId) {
                if (! User::whereKey($userId)->exists()) {
                    throw new InvalidArgumentException('Identity mapping references an unknown local user.');
                }
            }
            $counts = ['created' => 0, 'replayed' => 0];
            foreach (['topics', 'comments', 'outputs', 'archives'] as $type) {
                foreach ($manifest[$type] as $record) {
                    $author = $type === 'archives' ? null : ($manifest['identities'][$record['user_id']] ?? null);
                    if ($type !== 'archives' && ! $author) {
                        throw new InvalidArgumentException('Every author requires an explicit identity mapping.');
                    }
                    $recordChecksum = hash('sha256', json_encode([$record, $author], JSON_THROW_ON_ERROR));
                    $key = ['source_id' => $manifest['source_id'], 'record_type' => $type, 'legacy_id' => $record['id']];
                    $receipt = DB::table('legacy_import_receipts')->where($key)->lockForUpdate()->first();
                    if ($receipt) {
                        if (! hash_equals($receipt->checksum, $recordChecksum)) {
                            throw new InvalidArgumentException('Legacy record checksum conflict; nothing was overwritten.');
                        }
                        if ($receipt->target_type && ! ($receipt->target_type)::whereKey($receipt->target_id)->exists()) {
                            throw new InvalidArgumentException('Legacy target is missing; refusing to recreate it.');
                        }
                        $counts['replayed']++;

                        continue;
                    }
                    $target = $this->create($type, $record, $author, $manifest['source_id']);
                    DB::table('legacy_import_receipts')->insert([
                        ...$key, 'checksum' => $recordChecksum, 'manifest_checksum' => $checksum,
                        'target_type' => $target ? $target::class : null, 'target_id' => $target?->getKey(),
                        'archive_payload' => $type === 'archives' ? json_encode($record['payload'], JSON_THROW_ON_ERROR) : null,
                        'mapped_user_id' => $author, 'imported_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $counts['created']++;
                }
            }

            return $counts;
        });
    }

    private function validate(mixed $manifest): void
    {
        Validator::make(['manifest' => $manifest], ['manifest' => 'required|array:schema_version,source_id,identities,topics,comments,outputs,archives'])->validate();
        $rules = ['schema_version' => 'required|integer|in:1', 'source_id' => ['required', 'string', 'max:100', 'regex:/\A[a-zA-Z0-9_-]+\z/'],
            'identities' => 'required|array', 'identities.*' => 'required|integer|min:1'];
        foreach (['topics', 'comments', 'outputs', 'archives'] as $type) {
            $rules[$type] = 'present|array';
            $fields = match ($type) {
                'topics' => 'id,user_id,title,body,created_at,visibility,archetype,read_restricted,uploads,locked',
                'comments' => 'id,topic_id,user_id,body,created_at,visibility,archetype,read_restricted,uploads',
                'outputs' => 'id,user_id,title,content,created_at,visibility,sources,uploads',
                'archives' => 'id,kind,payload',
            };
            $rules[$type.'.*'] = 'required|array:'.$fields;
            $rules[$type.'.*.id'] = ['required', 'string', 'max:100', 'distinct', 'regex:/\A[a-zA-Z0-9_:-]+\z/'];
            if ($type !== 'archives') {
                $rules[$type.'.*.user_id'] = 'required|string|max:100';
                $rules[$type.'.*.created_at'] = 'required|date';
                $rules[$type.'.*.visibility'] = 'required|in:all_members';
                $rules[$type.'.*.'.($type === 'outputs' ? 'content' : 'body')] = 'required|string|max:1000000';
            }
        }
        $rules += ['topics.*.title' => 'required|string|max:255', 'topics.*.locked' => 'sometimes|boolean',
            'comments.*.topic_id' => 'required|string|max:100', 'outputs.*.title' => 'required|string|max:255',
            'outputs.*.sources' => 'sometimes|array', 'outputs.*.sources.*' => 'array:title,url',
            'outputs.*.sources.*.url' => 'required|url:http,https', 'outputs.*.sources.*.title' => 'sometimes|string|max:255',
            'archives.*.kind' => 'required|in:ghostfolio',
            'archives.*.payload' => 'required|array:accounts,activities,assetProfiles,meta,platforms,tags,user',
            'archives.*.payload.accounts' => 'present|array', 'archives.*.payload.activities' => 'present|array',
            'archives.*.payload.assetProfiles' => 'present|array', 'archives.*.payload.meta' => 'required|array:date,version',
            'archives.*.payload.user' => 'sometimes|array:settings', 'archives.*.payload.user.settings' => 'sometimes|array:currency,performanceCalculationType'];
        Validator::make($manifest, $rules)->validate();
        foreach ($manifest['identities'] as $userId) {
            if (! is_int($userId) || $userId < 1) {
                throw new InvalidArgumentException('Identity mappings must contain local integer user IDs only.');
            }
        }
        foreach (['topics', 'comments', 'outputs'] as $type) {
            foreach ($manifest[$type] as $record) {
                $body = $record[$type === 'outputs' ? 'content' : 'body'];
                if (($record['archetype'] ?? 'regular') !== 'regular' || ($record['read_restricted'] ?? false) !== false || ($record['uploads'] ?? []) !== [] || preg_match('~upload://|/uploads/|secure-uploads~i', $body)) {
                    throw new InvalidArgumentException('Private/restricted content and uploads require an accepted migration policy.');
                }
            }
        }
    }

    /** @param array<string, mixed> $record */
    private function create(string $type, array $record, ?int $author, string $source): mixed
    {
        if ($type === 'archives') {
            return null;
        }
        $created = CarbonImmutable::parse($record['created_at']);
        $common = ['user_id' => $author, 'created_at' => $created, 'updated_at' => $created];
        if ($type === 'topics') {
            return CommunityTopic::create([...$common, 'title' => $record['title'], 'body' => $record['body'], 'locked' => $record['locked'] ?? false]);
        }
        if ($type === 'comments') {
            $topic = DB::table('legacy_import_receipts')->where(['source_id' => $source, 'record_type' => 'topics', 'legacy_id' => $record['topic_id']])->first();
            if (! $topic || ! CommunityTopic::whereKey($topic->target_id)->exists()) {
                throw new InvalidArgumentException('Comment references an unmapped legacy topic.');
            }

            return CommunityComment::create([...$common, 'community_topic_id' => $topic->target_id, 'body' => $record['body']]);
        }

        return ResearchOutput::create([...$common, 'external_id' => 'legacy:'.$source.':'.$record['id'],
            'title' => $record['title'], 'kind' => 'research', 'status' => 'in_review', 'content' => $record['content'],
            'sources' => $record['sources'] ?? [], 'original_checksum' => hash('sha256', $record['content'])]);
    }
}
