<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NativeTradingSource
{
    private const ORIGIN = 'https://trading.tail84e234.ts.net';

    private const MAX_BYTES = 2_000_000;

    /** @var list<array<string, mixed>>|null */
    private ?array $manifest = null;

    private ?string $manifestAccount = null;

    public function configured(): bool
    {
        $account = config('trading.native_account');
        $fingerprint = config('trading.native_account_fingerprint');

        return config('trading.native_url') === self::ORIGIN
            && is_string($account) && $account === trim($account) && strlen($account) <= 200
            && preg_match('/\A[^\x00-\x1F\x7F]+\z/u', $account) === 1
            && is_string($fingerprint) && preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) === 1
            && hash_equals($fingerprint, hash('sha256', $account));
    }

    /** @return array{string, string} */
    private function get(string $path, string $type): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Native read-only source is not admitted.');
        }
        $response = Http::withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 5])
            ->connectTimeout(2)->timeout(5)->withHeaders(['Accept' => $type])
            ->get(self::ORIGIN.$path, ['account' => config('trading.native_account'), ...($path === '/api/research' ? ['limit' => 500] : [])]);
        $stream = $response->toPsrResponse()->getBody();
        try {
            $mediaType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
            if ($response->status() !== 200 || $mediaType !== $type || (int) $response->header('Content-Length') > self::MAX_BYTES) {
                throw new RuntimeException('Native read-only response is unavailable.');
            }
            $body = '';
            while (! $stream->eof() && strlen($body) <= self::MAX_BYTES) {
                $chunk = $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($body)));
                $body .= $chunk;
                if ($chunk === '') {
                    break;
                }
            }
            if (! $stream->eof() || $body === '' || strlen($body) > self::MAX_BYTES || ! mb_check_encoding($body, 'UTF-8')) {
                throw new RuntimeException('Native read-only response exceeds its content boundary.');
            }

            return [$body, hash('sha256', $body)];
        } finally {
            $stream->close();
        }
    }

    /** @return array{array<string, mixed>, string} */
    private function metadata(string $path): array
    {
        [$body, $hash] = $this->get($path, 'application/json');
        $envelope = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        $data = is_array($envelope) ? ($envelope['data'] ?? null) : null;
        if (! is_array($envelope) || ($envelope['ok'] ?? null) !== true || ! is_array($data)
            || ($data['account'] ?? null) !== config('trading.native_account')) {
            throw new RuntimeException('Native account selection does not match admission.');
        }

        return [$data, $hash];
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }
        if (! is_finite((float) $value) || $value <= 0 || $value > 100_000_000_000) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $value));

        return $date === false ? null : $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        [$data, $hash] = $this->metadata('/api/operations');
        $observed = $this->timestamp($data['observed_at'] ?? null);
        if ($observed === null) {
            throw new RuntimeException('Native observation timestamp is unavailable.');
        }
        $timer = is_array($data['timer'] ?? null) ? $data['timer'] : [];
        $next = null;
        if (is_string($timer['next_run'] ?? null) && preg_match('/\A[A-Za-z]{3} ([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}) UTC\z/', $timer['next_run'], $match)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $match[1], new DateTimeZone('UTC'));
            $next = $date && $date->format('Y-m-d H:i:s') === $match[1] ? $date->format(DATE_ATOM) : null;
        }

        return ['observed_at' => $observed, 'source' => 'native-paper-research', 'account' => $data['account'],
            'enabled' => is_bool($data['enabled'] ?? null) ? $data['enabled'] : null,
            'worker_active' => is_bool($data['worker_active'] ?? null) ? $data['worker_active'] : null,
            'schedule' => ['state' => $timer['state'] ?? 'unverified', 'next_run' => $next, 'native_next_run' => $timer['next_run'] ?? null],
            'latest_run' => $this->runMetadata($data['latest_run'] ?? null),
            'recent_runs' => array_values(array_filter(array_map($this->runMetadata(...), is_array($data['recent_runs'] ?? null) ? array_slice($data['recent_runs'], 0, 5) : []))),
            'metadata_response_sha256' => $hash,
            'provenance' => ['kind' => 'native-readonly-http', 'origin' => self::ORIGIN, 'account_fingerprint' => config('trading.native_account_fingerprint')]];
    }

    /** @return array<string, mixed>|null */
    private function runMetadata(mixed $row): ?array
    {
        return is_array($row) ? array_intersect_key($row, array_flip(['id', 'status', 'symbol', 'started_at', 'completed_at', 'card_status', 'outcome', 'has_report'])) : null;
    }

    /** @return list<array<string, mixed>> */
    public function reports(): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Native read-only source is not admitted.');
        }
        if ($this->manifest !== null && $this->manifestAccount === config('trading.native_account')) {
            return $this->manifest;
        }
        [$data, $hash] = $this->metadata('/api/research');
        if (! is_array($data['runs'] ?? null) || ! array_is_list($data['runs']) || count($data['runs']) > 500) {
            throw new RuntimeException('Native research list is malformed.');
        }
        $rows = [];
        foreach ($data['runs'] as $row) {
            if (! is_array($row) || ! $this->uuid($row['id'] ?? null) || $this->timestamp($row['started_at'] ?? null) === null
                || ! is_string($row['status'] ?? null) || strlen($row['status']) > 80 || ! is_bool($row['has_report'] ?? null)) {
                throw new RuntimeException('Native research identity is malformed.');
            }
            $rows[] = ['id' => $row['id'], 'title' => 'Scheduled research · '.(is_string($row['symbol'] ?? null) ? $row['symbol'] : 'unselected'),
                'created_at' => $this->timestamp($row['started_at']), 'started_at' => $row['started_at'], 'completed_at' => $row['completed_at'] ?? null,
                'status' => $row['status'], 'card_status' => $row['card_status'] ?? null, 'rating' => $row['rating'] ?? null,
                'account' => $data['account'], 'source' => 'scheduled-native-research', 'has_report' => $row['has_report'],
                'report_sha256' => null, 'receipt_sha256' => null, 'metadata_response_sha256' => $hash];
        }

        $this->manifestAccount = $data['account'];

        return $this->manifest = $rows;
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/', $value) === 1;
    }

    /** @return array<string, mixed> */
    public function reportArtifact(string $id): array
    {
        if (! $this->uuid($id)) {
            throw new RuntimeException('Invalid native report identity.');
        }
        foreach ($this->reports() as $row) {
            if ($row['id'] === $id && $row['has_report']) {
                [$body, $hash] = $this->get('/api/research/'.$id.'/report', 'text/html');

                return [...$row, 'content' => $body, 'media_type' => 'text/html', 'sha256' => $hash];
            }
        }
        throw new RuntimeException('Native saved report is unavailable.');
    }
}
