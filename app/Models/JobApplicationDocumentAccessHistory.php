<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplicationDocumentAccessHistory extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    public function jobApplication(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class);
    }

    public function accessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accessed_by');
    }

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'accessed_at' => 'datetime',
        ];
    }
}
