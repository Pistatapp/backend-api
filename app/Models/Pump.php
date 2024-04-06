<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Pump extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'farm_id',
        'name',
        'serial_number',
        'model',
        'manufacturer',
        'horsepower',
        'phase',
        'voltage',
        'ampere',
        'rpm',
        'pipe_size',
        'debi',
        'is_active',
        'is_healthy',
        'location',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_healthy' => 'boolean',
        'location' => 'array',
    ];

    /**
     * Get the farm that owns the pump.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get valves of the pump.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function valves()
    {
        return $this->hasMany(Valve::class);
    }

    /**
     * Get the attachments for the pump.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
