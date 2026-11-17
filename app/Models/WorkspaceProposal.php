<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $payload
 * @property ResearchSession $session
 * @property ResearchRun $run
 * @property-read CarbonImmutable|null $expires_at
 */
class WorkspaceProposal extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $appends = ['expires_at'];

    /** @return Attribute<CarbonImmutable|null, never> */
    protected function expiresAt(): Attribute
    {
        return Attribute::get(fn () => $this->created_at?->copy()->addDays(7));
    }

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /** @return BelongsTo<ResearchSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ResearchSession::class, 'session_id');
    }

    /** @return BelongsTo<ResearchRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class, 'run_id');
    }
}
