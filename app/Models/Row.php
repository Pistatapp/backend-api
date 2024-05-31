<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Row extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_id',
        'name',
        'coordinates',
    ];

    protected $casts = [
        'coordinates' => 'array',
    ];

    /**
     * Get the field that owns the row.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function field()
    {
        return $this->belongsTo(Field::class);
    }

    /**
     * Get the trees for the row.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function trees()
    {
        return $this->hasMany(Tree::class);
    }

    /**
     * Get the timars for the row.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function timars()
    {
        return $this->morphMany(Timar::class, 'timarable');
    }
}
