<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tractor_id',
        'farm_id',
        'name',
        'mobile',
        'employee_code',
    ];

    /**
     * Get the mobile number for the driver.
     *
     * @param string $driver
     * @param \Illuminate\Notifications\Notification|null $notification
     * @return string|array
     */
    public function routeNotificationForKavenegar($driver, $notification = null)
    {
        return $this->mobile;
    }

    /**
     * Set the employee code for the Driver
     *
     * @param string $value
     * @return void
     */
    public function setEmployeeCodeAttribute($value)
    {
        $existingCode = Driver::where('employee_code', $value)->exists();

        do {
            $value = random_int(1000000, 9999999);
        } while ($existingCode);

        $this->attributes['employee_code'] = $value;
    }

    /**
     * Get the tractor that owns the Driver
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tractor()
    {
        return $this->belongsTo(Tractor::class);
    }

    /**
     * Get the farm that owns the Driver
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
