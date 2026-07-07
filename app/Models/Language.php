<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    protected $fillable = [
        'name',
        'code',
    ];

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'profile_language')
            ->withPivot([
                'proficiency_level',
                'is_native',
                'notes',
            ])
            ->withTimestamps();
    }

    public function jobPostingRequirements(): HasMany
    {
        return $this->hasMany(JobPostingRequirement::class)->orderBy('position');
    }
}
