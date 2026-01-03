<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TractorEfficiencyChart extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tractor_efficiency_charts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tractor_id',
        'date',
        'total_efficiency',
        'task_based_efficiency',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_efficiency' => 'decimal:2',
            'task_based_efficiency' => 'decimal:2',
        ];
    }

    /**
     * Get the tractor that owns the efficiency chart.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tractor()
    {
        return $this->belongsTo(Tractor::class);
    }
}

