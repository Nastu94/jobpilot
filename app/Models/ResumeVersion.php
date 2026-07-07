<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResumeVersion extends Model
{
    protected $fillable = [
        'resume_id',
        'version_number',
        'source',
        'original_filename',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size',
        'checksum_sha256',
        'processing_status',
        'extracted_text',
    ];

    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }

    public function jobApplications(): HasMany
    {
        return $this->hasMany(JobApplication::class)->orderByDesc('applied_at');
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'file_size' => 'integer',
        ];
    }
}
