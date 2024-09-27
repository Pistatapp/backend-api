<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'user_id',
        'name',
        'coordinates',
        'crop_id',
        'center',
        'zoom',
        'area',
        'is_working_environment',
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
            'is_working_environment' => 'boolean',
        ];
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
     * Get the user that owns the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
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
     * Get blocks of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function blocks()
    {
        return $this->hasManyThrough(Block::class, Field::class);
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
     * Get trucktors of the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function trucktors()
    {
        return $this->hasMany(Trucktor::class);
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
     * Get the timars for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function timars()
    {
        return $this->hasMany(Timar::class);
    }

    /**
     * Get the plans for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function plans()
    {
        return $this->hasMany(Plan::class);
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
     * Get the cold requirement notifications for the farm.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function coldRequirementNotifications()
    {
        return $this->hasMany(ColdRequirementNotification::class);
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
}
