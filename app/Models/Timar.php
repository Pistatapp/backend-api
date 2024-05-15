<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Timar extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'color', 'description', 'farm_id'];

    /**
     * Get the farm that owns the timar.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
