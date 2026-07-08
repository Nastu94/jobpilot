<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplicationScheduledEvent extends Model
{
    public const TYPES = [
        'interview',
        'assessment',
        'recruiter_call',
        'follow_up',
        'deadline',
        'networking',
        'other',
    ];

    public const STATUSES = [
        'planned',
        'completed',
        'cancelled',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(
            JobApplicationScheduledEventHistory::class,
            'scheduled_event_id',
        )
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
