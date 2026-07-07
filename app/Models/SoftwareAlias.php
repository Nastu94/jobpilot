<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoftwareAlias extends Model
{
    protected $fillable = [
        'software_id',
        'alias',
        'normalized_alias',
    ];

    public function software(): BelongsTo
    {
        return $this->belongsTo(Software::class);
    }
}
