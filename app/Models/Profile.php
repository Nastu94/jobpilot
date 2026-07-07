<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    protected $fillable = [
        'user_id',
        'availability',
        'desired_ral_min',
        'desired_ral_max',
        'remote_preference',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'desired_ral_min' => 'integer',
            'desired_ral_max' => 'integer',
        ];
    }
}
