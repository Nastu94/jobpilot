<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'name',
        'website_url',
        'headquarters_location',
        'country_code',
    ];

    public function jobPostings(): HasMany
    {
        return $this->hasMany(JobPosting::class)->orderByDesc('published_at');
    }
}
