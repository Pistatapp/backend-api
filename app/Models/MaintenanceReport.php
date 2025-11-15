<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceReport extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'maintenance_id',
        'created_by',
        'maintained_by',
        'date',
        'description',
    ];

    /**
     * The relationships that should always be loaded.
     *
     * @var array<string>
     */
    protected $with = ['createdBy', 'maintainedBy:id,fname,lname', 'maintainable', 'maintenance:id,name'];

    /**
     * Get the maintenance that owns the MaintenanceReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function maintenance()
    {
        return $this->belongsTo(Maintenance::class);
    }

    /**
     * Get the model that owns the MaintenanceReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function maintainable()
    {
        return $this->morphTo();
    }

    /**
     * Get the user that owns the MaintenanceReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the employee that owns the MaintenanceReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function maintainedBy()
    {
        return $this->belongsTo(Employee::class, 'maintained_by');
    }
}
