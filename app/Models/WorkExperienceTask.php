<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkExperienceTask extends Model
{
    protected $fillable = [
        'work_experience_id',
        'description',
        'position',
    ];

    public function workExperience(): BelongsTo
    {
        return $this->belongsTo(WorkExperience::class);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
