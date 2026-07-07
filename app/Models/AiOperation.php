<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiOperation extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function matchAnalysis(): BelongsTo
    {
        return $this->belongsTo(MatchAnalysis::class);
    }

    public function generatedDocumentVersion(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocumentVersion::class);
    }

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'cost_micros' => 'integer',
            'payloads_stored' => 'boolean',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
