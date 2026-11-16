<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchSession extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pinned' => 'boolean'];
    }

    /** @return HasMany<ResearchMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ResearchMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<ResearchRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ResearchRun::class)->latest();
    }

    /** @return HasMany<BoardCard, $this> */
    public function board(): HasMany
    {
        return $this->hasMany(BoardCard::class)->oldest();
    }

    /** @return HasMany<WorkspaceProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(WorkspaceProposal::class, 'session_id')->oldest();
    }
}
