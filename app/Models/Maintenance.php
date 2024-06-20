<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Maintenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'name',
    ];

    /**
     * Get the farm that owns the Maintenance
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the maintenanceReports for the Maintenance
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function maintenanceReports()
    {
        return $this->hasMany(MaintenanceReport::class);
    }
}
