<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FarmReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'date',
        'operation_id',
        'labour_id',
        'description',
        'value',
    ];

    /**
     * Get the farm that owns the FarmReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the operation that owns the FarmReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Get the labour that owns the FarmReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function labour()
    {
        return $this->belongsTo(Labour::class);
    }

    /**
     * Get all of the owning reportable models.
     */
    public function reportable()
    {
        return $this->morphTo();
    }
}
