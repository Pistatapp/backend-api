<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Plot extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'coordinates',
        'field_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordinates' => 'array',
        ];
    }

    /**
     * Get the field that owns the plot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function field()
    {
        return $this->belongsTo(Field::class);
    }

    /**
     * Get the rows for the plot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function rows()
    {
        return $this->hasMany(Row::class);
    }

    /**
     * Get the attachments for the plot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get the valves for the plot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function valves()
    {
        return $this->hasMany(Valve::class);
    }

    /**
     * Get the irrigations for the plot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function irrigations()
    {
        return $this->belongsToMany(Irrigation::class);
    }

    /**
     * Get the reports for the plot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function reports()
    {
        return $this->morphMany(FarmReport::class, 'reportable');
    }

    /**
     * Get the trees for the plot.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyDeep
     */
    public function trees()
    {
        return $this->hasManyDeep(Tree::class, [Row::class]);
    }
}
