<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplicationTrackingHistory extends Model
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

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    protected function casts(): array
    {
        return [
            'previous_next_action_at' => 'datetime',
            'next_action_at' => 'datetime',
            'changed_at' => 'datetime',
        ];
    }
}
