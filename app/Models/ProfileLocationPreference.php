<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileLocationPreference extends Model
{
    protected $fillable = [
        'profile_id',
        'location',
        'country_code',
        'position',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
