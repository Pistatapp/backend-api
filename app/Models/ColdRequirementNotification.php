<?php

namespace App\Models;

use App\Casts\JalaliDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ColdRequirementNotification extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'farm_id',
        'start_dt',
        'end_dt',
        'min_temp',
        'max_temp',
        'cold_requirement',
        'method',
        'note',
        'notified',
        'notified_at',
    ];

    protected $attributes = [
        'notified' => false,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, mixed>
     */
    protected $casts = [
        'start_dt' => JalaliDate::class,
        'end_dt' => JalaliDate::class,
        'notified' => 'boolean',
        'notified_at' => 'datetime',
    ];

    /**
     * Get the farm that owns the cold requirement notification.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
