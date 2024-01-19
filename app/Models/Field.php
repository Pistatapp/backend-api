<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Field extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'name',
        'coordinates',
        'center',
        'area',
        'products',
    ];

    protected $casts = [
        'coordinates' => 'array',
    ];

    /**
     * Get the farm that owns the field.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the rows for the field.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function rows()
    {
        return $this->hasMany(Row::class);
    }

    /**
     * Determine if the field has rows.
     * 
     * @return bool
     */
    public function hasRows()
    {
        return $this->rows()->exists();
    }

    /**
     * Get the blocks for the field.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function blocks()
    {
        return $this->hasMany(Block::class);
    }
}
