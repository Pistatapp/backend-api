<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Field extends Model
{
    use HasFactory, HasRelationships;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'farm_id',
        'name',
        'coordinates',
        'center',
        'area',
        'crop_type_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'coordinates' => 'array',
            'center' => 'array',
        ];
    }

    /**
     * Get the crop type that owns the field.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cropType()
    {
        return $this->belongsTo(CropType::class);
    }

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

    /**
     * Get attachments for the field.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get the field's irrigations.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function irrigations()
    {
        return $this->belongsToMany(Irrigation::class);
    }

    /**
     * Get the field's plans.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function timars()
    {
        return $this->morphMany(Timar::class, 'timarable');
    }

    /**
     * Get the field's reports.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function reports()
    {
        return $this->morphMany(FarmReport::class, 'reportable');
    }

    /**
     * Get the field's valves.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function valves()
    {
        return $this->hasMany(Valve::class);
    }
}
