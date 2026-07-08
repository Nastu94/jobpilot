<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplicationInteraction extends Model
{
    public const TYPES = [
        'email',
        'phone_call',
        'recruiter_message',
        'interview',
        'assessment',
        'offer',
        'networking',
        'other',
    ];

    public const DIRECTIONS = [
        'inbound',
        'outbound',
        'meeting',
        'internal',
    ];

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
            'occurred_at' => 'datetime',
        ];
    }
}
