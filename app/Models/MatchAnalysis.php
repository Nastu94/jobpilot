<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchAnalysis extends Model
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

    public function resumeVersion(): BelongsTo
    {
        return $this->belongsTo(ResumeVersion::class);
    }

    public function factors(): HasMany
    {
        return $this->hasMany(MatchFactor::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function generatedDocumentVersions(): HasMany
    {
        return $this->hasMany(GeneratedDocumentVersion::class)
            ->orderByDesc('version_number');
    }

    public function aiOperations(): HasMany
    {
        return $this->hasMany(AiOperation::class)
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    protected function casts(): array
    {
        return [
            'score_bps' => 'integer',
            'calculated_at' => 'datetime',
        ];
    }
}
