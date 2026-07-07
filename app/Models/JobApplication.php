<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplication extends Model
{
    protected $guarded = [
        'id',
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

    public function statusHistory(): HasMany
    {
        return $this->hasMany(JobApplicationStatusHistory::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'next_action_at' => 'datetime',
        ];
    }
}
