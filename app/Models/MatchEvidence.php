<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchEvidence extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function matchFactor(): BelongsTo
    {
        return $this->belongsTo(MatchFactor::class);
    }

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'position' => 'integer',
        ];
    }
}
