<?php

namespace App\Models;

use App\Traits\Searchable;
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
    use Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'mobile',
        'fcm_token',
        'created_by',
        'preferences->language',
        'preferences->theme',
        'preferences->notifications_enabled',
        'preferences->working_environment',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array<string>
     */
    protected $hidden = [
        'password',
        'password_expires_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts()
    {
        return [
            'mobile_verified_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'created_by' => 'integer',
            'password' => 'hashed',
            'password_expires_at' => 'datetime',
            'preferences' => 'array',
            'is_online' => 'boolean',
        ];
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            // Only set default preferences if preferences weren't explicitly set
            if (!$user->isDirty('preferences')) {
                $user->preferences = [
                    'language' => config('app.locale', 'en'),
                    'theme' => 'light',
                    'notifications_enabled' => true,
                    'working_environment' => null
                ];
            }
        });
    }

    /**
     * The relationships that should always be loaded.
     *
     * @var array<string>
     */
    protected $with = ['roles'];

    /**
     * Mark the user's mobile as verified.
     *
     * @return void
     */
    public function markMobileAsVerified()
    {
        $this->forceFill([
            'mobile_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    /**
     * Determine if the user has verified their mobile number.
     *
     * @return bool
     */
    public function hasVerifiedMobile()
    {
        return ! is_null($this->mobile_verified_at);
    }

    /**
     * Determine if the password is not expired.
     *
     * @return bool
     */
    public function passwordNotExpired()
    {
        return $this->password_expires_at &&
            $this->password_expires_at->isFuture();
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
    public function creator()
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
     * Get the user's working environment farm.
     *
     * @return \App\Models\Farm|null
     */
    public function workingEnvironment()
    {
        return $this->farms()
            ->where('farms.id', $this->preferences['working_environment'] ?? null)
            ->first();
    }

    /**
     * Get the user's payments
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get the user's chat rooms.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function chatRooms()
    {
        return $this->belongsToMany(ChatRoom::class, 'chat_room_user')
            ->withPivot('joined_at', 'left_at', 'last_read_at', 'is_muted')
            ->withTimestamps();
    }

    /**
     * Get the user's active chat rooms (not left).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function activeChatRooms()
    {
        return $this->belongsToMany(ChatRoom::class, 'chat_room_user')
            ->wherePivotNull('left_at')
            ->withPivot('joined_at', 'left_at', 'last_read_at', 'is_muted')
            ->withTimestamps();
    }

    /**
     * Get the messages sent by the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
