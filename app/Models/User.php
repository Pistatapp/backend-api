<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, Notifiable, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'mobile',
        'mobile_verified_at',
        'last_activity_at',
        'avatar',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'mobile_verified_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    /**
     * Get the user's mobile number.
     *
     * @return string
     */
    public function routeNotificationForKavenegar($driver, $notification = null)
    {
        return $this->mobile;
    }

    /**
     * Get the user's profile.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * Get the user's farms.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function farms()
    {
        return $this->hasMany(Farm::class);
    }

    /**
     * Determine if user has any farms.
     * 
     * @return bool
     */
    public function hasFarm()
    {
        return $this->farms()->exists();
    }
}
