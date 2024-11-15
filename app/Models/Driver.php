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
        'trucktor_id',
        'name',
        'mobile',
        'employee_code',
    ];

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
     * Get the trucktor that owns the Driver
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function trucktor()
    {
        return $this->belongsTo(Trucktor::class);
    }
}
