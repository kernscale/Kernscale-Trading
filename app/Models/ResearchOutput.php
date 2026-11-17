<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $provenance
 */
class ResearchOutput extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['derivation_key'];

    protected function casts(): array
    {
        return ['sources' => 'array', 'provenance' => 'array', 'source_output_version' => 'integer', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<ResearchRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ResearchRun::class, 'research_run_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->select('id', 'name');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function ($query) use ($user) {
            $query->where('status', 'published')->orWhere('user_id', $user->id);
            if ($user->hasRole('owner')) {
                $query->orWhere('status', 'in_review')->orWhereNotNull('external_id');
            }
        });
    }
}
