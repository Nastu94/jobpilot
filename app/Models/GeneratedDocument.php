<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneratedDocument extends Model
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

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GeneratedDocumentVersion::class)
            ->orderByDesc('version_number');
    }
}
