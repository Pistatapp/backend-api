<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Row extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'field_id',
        'name',
        'coordinates',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @return array
     */
    protected function casts()
    {
        return [
            'coordinates' => 'array',
        ];
    }

    /**
     * Get the length of the row.
     *
     * @return float
     */
    public function getLengthAttribute()
    {
        if (!isset($this->coordinates[0]) || !isset($this->coordinates[1])) {
            return 0;
        }

        // Parse coordinates from "lat,lng" format to [lat, lng] arrays
        $point1 = explode(',', $this->coordinates[0]);
        $point2 = explode(',', $this->coordinates[1]);

        if (count($point1) !== 2 || count($point2) !== 2) {
            return 0;
        }

        return calculate_distance(
            [floatval($point1[0]), floatval($point1[1])],
            [floatval($point2[0]), floatval($point2[1])]
        );
    }

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
     * Get the treatments for the row.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function treatments()
    {
        return $this->morphMany(Treatment::class, 'treatable');
    }

    /**
     * Get the reports for the row.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function reports()
    {
        return $this->morphMany(FarmReport::class, 'reportable');
    }
}
