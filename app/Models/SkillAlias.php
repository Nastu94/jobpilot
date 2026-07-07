<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkillAlias extends Model
{
    protected $guarded = [];

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
