<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplicationScheduledEventHistory extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function scheduledEvent(): BelongsTo
    {
        return $this->belongsTo(
            JobApplicationScheduledEvent::class,
            'scheduled_event_id',
        );
    }

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
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
