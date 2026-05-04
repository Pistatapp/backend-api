<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TractorTaskTaskable extends Model
{
    /**
     * @var array<string>
     */
    protected $fillable = [
        'tractor_task_id',
        'taskable_type',
        'taskable_id',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function tractorTask(): BelongsTo
    {
        return $this->belongsTo(TractorTask::class);
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }
}
