<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkExperience extends Model
{
    protected $fillable = [
        'profile_id',
        'company_name',
        'job_title',
        'location',
        'start_date',
        'end_date',
        'is_current',
        'description',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(WorkExperienceTask::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
        ];
    }
}
