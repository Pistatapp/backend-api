<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Crop extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_by' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = ['name', 'cold_requirement', 'created_by', 'is_active'];


    /**
     * Get the user that created the crop.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the farms for the crop.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function farms()
    {
        return $this->hasMany(Farm::class);
    }

    /**
     * Get the crop types for the crop.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function cropTypes()
    {
        return $this->hasMany(CropType::class);
    }

    /**
     * Scope a query to only include global crops (created by root users).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('created_by');
    }

    /**
     * Scope a query to include both global crops and user-specific crops.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAccessibleByUser($query, $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereNull('created_by')
              ->orWhere('created_by', $userId);
        });
    }

    /**
     * Determine if the crop is global (accessible to all users).
     *
     * @return bool
     */
    public function isGlobal(): bool
    {
        return is_null($this->created_by);
    }

    /**
     * Determine if the crop is owned by the given user.
     *
     * @param  int  $userId
     * @return bool
     */
    public function isOwnedBy($userId): bool
    {
        return $this->created_by === $userId;
    }
}
