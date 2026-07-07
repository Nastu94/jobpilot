<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Profile extends Model
{
    protected $fillable = [
        'user_id',
        'availability',
        'desired_ral_min',
        'desired_ral_max',
        'remote_preference',
        'willing_to_relocate',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class)->withTimestamps();
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)
            ->withPivot([
                'proficiency_level',
                'years_experience',
                'source',
                'is_approved',
                'notes',
            ])
            ->withTimestamps();
    }

    public function software(): BelongsToMany
    {
        return $this->belongsToMany(Software::class, 'profile_software')
            ->withPivot([
                'proficiency_level',
                'years_experience',
                'source',
                'is_approved',
                'notes',
            ])
            ->withTimestamps();
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'profile_language')
            ->withPivot([
                'proficiency_level',
                'is_native',
                'notes',
            ])
            ->withTimestamps();
    }

    public function workExperiences(): HasMany
    {
        return $this->hasMany(WorkExperience::class)->orderByDesc('start_date');
    }

    public function academicHistory(): HasMany
    {
        return $this->hasMany(Education::class)->orderByDesc('start_date');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(Certification::class)->orderByDesc('issue_date');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->orderByDesc('start_date');
    }

    public function locationPreferences(): HasMany
    {
        return $this->hasMany(ProfileLocationPreference::class)
            ->orderBy('position')
            ->orderBy('id');
    }

    public function resumes(): HasMany
    {
        return $this->hasMany(Resume::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class)
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function jobApplications(): HasMany
    {
        return $this->hasMany(JobApplication::class)
            ->orderByDesc('applied_at')
            ->orderByDesc('id');
    }

    public function matchAnalyses(): HasMany
    {
        return $this->hasMany(MatchAnalysis::class)
            ->orderByDesc('calculated_at')
            ->orderByDesc('id');
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class)->orderByDesc('id');
    }

    protected function casts(): array
    {
        return [
            'desired_ral_min' => 'integer',
            'desired_ral_max' => 'integer',
            'willing_to_relocate' => 'boolean',
        ];
    }
}
