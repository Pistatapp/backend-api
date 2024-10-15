<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PhonologyGuideFile extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = ['name', 'created_by'];

    /**
     * The relationships that should always be loaded.
     *
     * @var string[]
     */
    protected $with = ['user'];

    /**
     * Get the owning phonology guide fileable model.
     */
    public function phonologyable()
    {
        return $this->morphTo();
    }

    /**
     * Get the user that created the phonology guide file.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
