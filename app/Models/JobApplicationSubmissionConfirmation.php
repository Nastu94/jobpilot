<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplicationSubmissionConfirmation extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'document_version_number' => 'integer',
        ];
    }
}
