<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'province',
        'city',
        'company',
        'personnel_number',
    ];

    /**
     * Get the user that owns the profile.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the media url of the profile.
     *
     * @return string|null
     */
    public function getMediaUrlAttribute()
    {
        return $this->user->getFirstMediaUrl('image');
    }

    /**
     * The "booted" method of the model.
     * Generates a unique personnel_number during profile creation.
     *
     * @return void
     */
    protected static function booted()
    {
        static::creating(function ($profile) {
            if (empty($profile->personnel_number)) {
                do {
                    // Generate a unique 8-digit personnel_number
                    $number = str_pad(mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);
                } while (self::where('personnel_number', $number)->exists());
                $profile->personnel_number = $number;
            }
        });
    }
}
