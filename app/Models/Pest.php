<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Pest extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'name',
        'scientific_name',
        'description',
        'damage',
        'management',
        'standard_day_degree',
        'created_by',
    ];

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'standard_day_degree' => 22.5,
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
        ];
    }

    /**
     * Get the phonology guide files for the pest.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function phonologyGuideFiles()
    {
        return $this->morphMany(PhonologyGuideFile::class, 'phonologyable');
    }

    /**
     * Get the user that created the pest.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope a query to only include global pests (created by root users).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('created_by');
    }

    /**
     * Scope a query to only include user-specific pests.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('created_by', $userId);
    }

    /**
     * Scope a query to include both global pests and user-specific pests.
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
     * Determine if the pest is global (accessible to all users).
     *
     * @return bool
     */
    public function isGlobal()
    {
        return is_null($this->created_by);
    }

    /**
     * Determine if the pest is owned by the given user.
     *
     * @param  int  $userId
     * @return bool
     */
    public function isOwnedBy($userId)
    {
        return $this->created_by === $userId;
    }
}
