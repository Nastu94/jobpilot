<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Software extends Model
{
    protected $fillable = ['name', 'normalized_name', 'vendor', 'category'];

    public function aliases(): HasMany
    {
        return $this->hasMany(SoftwareAlias::class);
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'profile_software')
            ->withPivot(['proficiency_level', 'years_experience', 'source', 'is_approved', 'notes'])
            ->withTimestamps();
    }

    public function jobPostingRequirements(): HasMany
    {
        return $this->hasMany(JobPostingRequirement::class)->orderBy('position');
    }
}
