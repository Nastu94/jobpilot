<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneratedDocumentVersion extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function generatedDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class);
    }

    public function sourceResumeVersion(): BelongsTo
    {
        return $this->belongsTo(ResumeVersion::class, 'source_resume_version_id');
    }

    public function matchAnalysis(): BelongsTo
    {
        return $this->belongsTo(MatchAnalysis::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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
            'version_number' => 'integer',
            'file_size' => 'integer',
            'contains_unverified_claims' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }
}
