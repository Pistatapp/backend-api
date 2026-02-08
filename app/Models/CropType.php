<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class CropType extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = ['name', 'standard_day_degree', 'created_by', 'is_active', 'load_estimation_data'];

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'standard_day_degree' => 22.5,
        'is_active' => true,
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'standard_day_degree' => 'float',
            'created_by' => 'integer',
            'is_active' => 'boolean',
            'load_estimation_data' => 'array',
        ];
    }

    /**
     * Get the user that created the crop type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the crop that owns the crop type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function crop()
    {
        return $this->belongsTo(Crop::class);
    }

    /**
     * Get the fields for the crop type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function fields()
    {
        return $this->hasMany(Field::class);
    }

    /**
     * Get the phonology files for the crop type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function phonologyGuideFiles()
    {
        return $this->morphMany(PhonologyGuideFile::class, 'phonologyable');
    }

    /**
     * Get the load estimation table for the crop type.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function loadEstimationTable()
    {
        return $this->hasOne(LoadEstimationTable::class);
    }

    /**
     * Scope a query to only include global crop types (created by root users).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('created_by');
    }

    /**
     * Scope a query to include both global crop types and user-specific crop types.
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
     * Determine if the crop type is global (accessible to all users).
     *
     * @return bool
     */
    public function isGlobal(): bool
    {
        return is_null($this->created_by);
    }

    /**
     * Determine if the crop type is owned by the given user.
     *
     * @param  int  $userId
     * @return bool
     */
    public function isOwnedBy($userId): bool
    {
        return $this->created_by === $userId;
    }
}
