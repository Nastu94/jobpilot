<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplicationScheduledEventReplacement extends Model
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

    public function previousEvent(): BelongsTo
    {
        return $this->belongsTo(
            JobApplicationScheduledEvent::class,
            'previous_scheduled_event_id',
        );
    }

    public function replacementEvent(): BelongsTo
    {
        return $this->belongsTo(
            JobApplicationScheduledEvent::class,
            'replacement_scheduled_event_id',
        );
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }
}
