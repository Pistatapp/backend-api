<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Farm extends Model
{
    use HasFactory, HasRelationships;

    protected $fillable = [
        'user_id',
        'name',
        'coordinates',
        'products',
        'center',
        'zoom',
        'area',
    ];

    protected $casts = [
        'coordinates' => 'array',
        'products' => 'array',
        'center' => 'array',
    ];

    /**
     * Get the user that owns the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get fields of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function fields()
    {
        return $this->hasMany(Field::class);
    }

    /**
     * Get blocks of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function blocks()
    {
        return $this->hasManyThrough(Block::class, Field::class);
    }

    /**
     * Get rows of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyDeep
     */
    public function rows()
    {
        return $this->hasManyDeep(Row::class, [Field::class]);
    }

    /**
     * Get trees of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyDeep
     */
    public function trees()
    {
        return $this->hasManyDeep(Tree::class, [Field::class, Row::class]);
    }

    /**
     * Get pumps of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function pumps()
    {
        return $this->hasMany(Pump::class);
    }

    /**
     * Get trucktors of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function trucktors()
    {
        return $this->hasMany(Trucktor::class);
    }

    /**
     * Get teams of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Get operations of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function operations()
    {
        return $this->hasMany(Operation::class);
    }
}
