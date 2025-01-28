<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, Notifiable, InteractsWithMedia, HasRoles;

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
        'is_admin',
        'fcm_token',
        'created_by',
    ];

    /**
     * The attributes that should have default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @return array<string, string>
     */
    protected function casts()
    {
        return [
            'mobile_verified_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'is_admin' => 'boolean',
            'created_by' => 'integer',
        ];
    }

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
     * Get the user that created the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function farms()
    {
        return $this->belongsToMany(Farm::class)
            ->using(FarmUser::class)
            ->withPivot('is_owner', 'role');
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

    /**
     * Get the user's gps devices.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gpsDevices()
    {
        return $this->hasMany(GpsDevice::class);
    }

    /**
     * Determine if user is admin.
     *
     * @return bool
     */
    public function isAdmin()
    {
        return $this->is_admin === true;
    }

    /**
     * Scope a query to only include users that are not admin.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotAdmin($query)
    {
        return $query->where('is_admin', false);
    }

    /**
     * Get the active farm of the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function active_farm()
    {
        return $this->hasOne(Farm::class)->where('is_working_environment', true);
    }
}
