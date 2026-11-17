<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed>|null $workspace
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property ResearchSession $session
 * @property User $user
 */
class ResearchRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'workspace' => 'array', 'attachments' => 'array'];
    }

    /** @return BelongsTo<ResearchSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ResearchSession::class, 'research_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ResearchEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ResearchEvent::class)->orderBy('remote_id');
    }
}
