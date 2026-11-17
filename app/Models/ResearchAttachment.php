<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ResearchAttachment extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['storage_key', 'text', 'user_id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        return $this->only(['id', 'name', 'media_type', 'size', 'sha256']);
    }
}
