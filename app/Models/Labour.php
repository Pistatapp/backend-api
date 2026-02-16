<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Labour extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'farm_id',
        'name',
        'personnel_number',
        'mobile',
        'image',
        'user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string|mixed>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * Get the teams that the Labour belongs to
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teams()
    {
        return $this->belongsToMany(Team::class, 'labour_team');
    }

    /**
     * Get the farm that owns the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the user associated with the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get labour irrigation programs
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function irrigations()
    {
        return $this->hasMany(Irrigation::class, 'labour_id');
    }
}

