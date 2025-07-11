<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Staudenmeir\EloquentHasManyDeep\HasRelationships;

class Farm extends Model
{
    use HasFactory, HasRelationships;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'coordinates',
        'crop_id',
        'center',
        'zoom',
        'area',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts()
    {
        return [
            'coordinates' => 'array',
            'center' => 'array',
        ];
    }

    /**
     * Determine if the farm is a working environment of the current user.
     *
     * @return bool
     */
    public function isWorkingEnvironment()
    {
        return $this->id === (Auth::user()->preferences['working_environment'] ?? null);
    }

    /**
     * Get the crop that owns the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function crop()
    {
        return $this->belongsTo(Crop::class);
    }

    /**
     * The users that belong to the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('is_owner', 'role');
    }

    /**
     * Get the admins of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function admins()
    {
        return $this->belongsToMany(User::class)->wherePivot('role', 'admin');
    }

    /**
     * Get fields of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function fields()
    {
        return $this->hasMany(Field::class);
    }

    /**
     * Get plots of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function plots()
    {
        return $this->hasManyThrough(Plot::class, Field::class);
    }

    /**
     * Get rows of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyDeep
     */
    public function rows()
    {
        return $this->hasManyDeep(Row::class, [Field::class]);
    }

    /**
     * Get trees of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyDeep
     */
    public function trees()
    {
        return $this->hasManyDeep(Tree::class, [Field::class, Row::class]);
    }

    /**
     * Get pumps of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function pumps()
    {
        return $this->hasMany(Pump::class);
    }

    /**
     * Get tractors of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tractors()
    {
        return $this->hasMany(Tractor::class);
    }

    /**
     * Get teams of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function teams()
    {
        return $this->hasMany(Team::class);
    }

    /**
     * Get operations of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function operations()
    {
        return $this->hasMany(Operation::class);
    }

    /**
     * Get maintenances of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function maintenances()
    {
        return $this->hasMany(Maintenance::class);
    }

    /**
     * Get the maintenance reports for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function maintenanceReports()
    {
        return $this->through('maintenances')->has('maintenanceReports');
    }

    /**
     * Get the treatments for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function treatments()
    {
        return $this->hasMany(Treatment::class);
    }

    /**
     * Get the farm plans for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function plans()
    {
        return $this->hasMany(FarmPlan::class);
    }

    /**
     * Get the labours for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function laboursInTeams()
    {
        return $this->through('teams')->has('labours');
    }

    /**
     * Get the labours for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function labours()
    {
        return $this->hasMany(Labour::class);
    }

    /**
     * Get the volk oil sprays for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function volkSprayNotfications()
    {
        return $this->hasMany(VolkOilSpray::class);
    }

    /**
     * Get the farm reports for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reports()
    {
        return $this->hasMany(FarmReport::class);
    }

    /**
     * Get the irrigations for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function irrigations()
    {
        return $this->hasMany(Irrigation::class);
    }

    /**
     * Get the valves of the farm through pumps.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function valves()
    {
        return $this->hasManyThrough(Valve::class, Pump::class);
    }

    /**
     * Get frostbit risks of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function frostbitRisks()
    {
        return $this->hasMany(FrostbitRisk::class);
    }

    /**
     * Get the warnings of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function warnings()
    {
        return $this->hasMany(Warning::class);
    }

    /**
     * Get the drivers of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function drivers()
    {
        return $this->hasMany(Driver::class);
    }
}
