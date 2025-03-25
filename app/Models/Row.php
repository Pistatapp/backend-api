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
