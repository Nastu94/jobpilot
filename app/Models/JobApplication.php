<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JobApplication extends Model
{
    protected $guarded = [
        'id',
        'submitted_generated_document_version_id',
        'submitted_source_resume_version_id',
        'submitted_document_version_number',
        'submitted_document_filename',
        'submitted_document_mime_type',
        'submitted_document_file_size',
        'submitted_document_checksum_sha256',
        'submitted_document_content_sha256',
        'submitted_document_storage_disk',
        'submitted_document_storage_path',
        'submitted_document_generator_key',
        'submitted_document_generator_version',
        'submitted_document_reviewed_at',
        'submitted_context_captured_at',
        'submitted_job_posting_id',
        'submitted_job_title',
        'submitted_company_name',
        'submitted_job_source',
        'submitted_job_location',
        'submitted_job_country_code',
        'submitted_job_remote_type',
        'submitted_job_employment_type',
        'submitted_job_seniority',
        'submitted_application_channel',
        'submitted_external_reference',
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

    public function generatedDocumentVersion(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocumentVersion::class);
    }

    public function submissionConfirmation(): HasOne
    {
        return $this->hasOne(JobApplicationSubmissionConfirmation::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(JobApplicationStatusHistory::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    public function trackingHistory(): HasMany
    {
        return $this->hasMany(JobApplicationTrackingHistory::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    public function documentVersionHistory(): HasMany
    {
        return $this->hasMany(JobApplicationDocumentVersionHistory::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    public function documentAccessHistory(): HasMany
    {
        return $this->hasMany(JobApplicationDocumentAccessHistory::class)
            ->orderBy('accessed_at')
            ->orderBy('id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(JobApplicationInteraction::class)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function scheduledEvents(): HasMany
    {
        return $this->hasMany(JobApplicationScheduledEvent::class)
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    public function scheduledEventReplacements(): HasMany
    {
        return $this->hasMany(JobApplicationScheduledEventReplacement::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class)->orderByDesc('id');
    }

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'next_action_at' => 'datetime',
            'submitted_document_reviewed_at' => 'datetime',
            'submitted_context_captured_at' => 'datetime',
        ];
    }
}
