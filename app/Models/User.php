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
            'created_by' => 'integer',
            'password' => 'hashed',
            'password_expires_at' => 'datetime',
            'preferences' => 'array',
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
     * Get the user's labour.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function labour()
    {
        return $this->hasOne(Labour::class);
    }

    /**
     * Get the user's attendance tracking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function attendanceTracking()
    {
        return $this->hasOne(AttendanceTracking::class);
    }

    /**
     * Get the user's attendance sessions.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attendanceSessions()
    {
        return $this->hasMany(AttendanceSession::class);
    }

    /**
     * Get the user's attendance daily reports.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function dailyReports()
    {
        return $this->hasMany(AttendanceDailyReport::class);
    }

    /**
     * Get the user's attendance monthly payrolls.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function monthlyPayrolls()
    {
        return $this->hasMany(AttendanceMonthlyPayroll::class);
    }

    /**
     * Get the user's attendance GPS data.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attendanceGpsData()
    {
        return $this->hasMany(AttendanceGpsData::class);
    }

    /**
     * Get the user's attendance shift schedules.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function shiftSchedules()
    {
        return $this->hasMany(AttendanceShiftSchedule::class);
    }

    /**
     * Get the user's current shift schedule for today.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function currentShiftSchedule()
    {
        return $this->hasOne(AttendanceShiftSchedule::class)->whereDate('scheduled_date', now());
    }
}
