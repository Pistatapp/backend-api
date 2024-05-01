<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    use HasFactory;

    protected $fillable = [
        'farm_id',
        'parent_id',
        'name',
    ];

    /**
     * Get the parent that owns the Operation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Get the children for the Operation
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function children()
    {
        return $this->hasMany(Operation::class, 'parent_id');
    }

    /**
     * Get the farm that owns the Operation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
