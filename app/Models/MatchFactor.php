<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchFactor extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function matchAnalysis(): BelongsTo
    {
        return $this->belongsTo(MatchAnalysis::class);
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(MatchEvidence::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'weight_bps' => 'integer',
            'score_bps' => 'integer',
            'contribution_bps' => 'integer',
            'position' => 'integer',
        ];
    }
}
