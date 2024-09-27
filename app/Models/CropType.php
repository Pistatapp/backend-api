<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CropType extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

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
}
