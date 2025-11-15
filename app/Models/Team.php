<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = ['farm_id', 'name', 'supervisor_id'];

    /**
     * Get the farm that owns the team.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get all of the employees for the Team
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function labours()
    {
        return $this->belongsToMany(Employee::class, 'employee_team');
    }

    /**
     * Get all of the employees for the Team (alias for labours)
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_team');
    }

    /**
     * Get the supervisor associated with the Team
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function supervisor()
    {
        return $this->belongsTo(Employee::class, 'supervisor_id', 'id');
    }
}
