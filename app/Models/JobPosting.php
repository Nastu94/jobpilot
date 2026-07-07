<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    protected $fillable = [
        'profile_id',
        'company_id',
        'title',
        'company_name',
        'source',
        'external_id',
        'source_url',
        'location',
        'country_code',
        'remote_type',
        'employment_type',
        'seniority',
        'salary_min',
        'salary_max',
        'currency',
        'status',
        'processing_status',
        'description',
        'raw_content',
        'content_hash',
        'published_at',
        'expires_at',
        'captured_at',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jobApplications(): HasMany
    {
        return $this->hasMany(JobApplication::class)->orderByDesc('applied_at');
    }

    public function matchAnalyses(): HasMany
    {
        return $this->hasMany(MatchAnalysis::class)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id');
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class)->orderByDesc('id');
    }

    public function aiOperations(): HasMany
    {
        return $this->hasMany(AiOperation::class)
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(JobPostingRequirement::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function approvedRequirements(): HasMany
    {
        return $this->hasMany(JobPostingRequirement::class)
            ->approved()
            ->orderBy('position')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'salary_min' => 'integer',
            'salary_max' => 'integer',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'captured_at' => 'datetime',
        ];
    }
}
