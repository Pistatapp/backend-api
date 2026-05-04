<?php

namespace App\Models;

use App\Casts\Time;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TractorTask extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tractor_id',
        'operation_id',
        'date',
        'start_time',
        'end_time',
        'status',
        'created_by',
        'data->consumed_water',
        'data->consumed_fertilizer',
        'data->consumed_poison',
        'data->operation_area',
        'data->workers_count'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'not_started',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected function casts()
    {
        return [
            'date' => 'date',
            'start_time' => Time::class,
            'end_time' => Time::class,
            'created_by' => 'integer',
            'data' => 'array',
        ];
    }

    /**
     * Get the GPS metrics calculation for this task
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function gpsMetricsCalculation()
    {
        return $this->hasOne(GpsMetricsCalculation::class);
    }

    /**
     * Get the tractor that owns the TractorTask
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tractor()
    {
        return $this->belongsTo(Tractor::class);
    }

    /**
     * Farm for the tractor this task is assigned to (used by policies and notifications).
     */
    public function getFarmAttribute(): ?Farm
    {
        return $this->tractor?->farm;
    }

    /**
     * Get the operation that owns the TractorTask
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Ordered pivot rows for all locations (fields, plots, etc.) covered by this task.
     *
     * @return HasMany<TractorTaskTaskable, TractorTask>
     */
    public function taskableItems(): HasMany
    {
        return $this->hasMany(TractorTaskTaskable::class)->orderBy('sort_order');
    }

    /**
     * Replace linked taskables on the pivot table.
     *
     * @param  array<int>  $taskableIds
     */
    public function syncTaskableItems(string $taskableType, array $taskableIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $taskableIds)));
        $this->taskableItems()->delete();

        foreach ($ids as $order => $id) {
            $this->taskableItems()->create([
                'taskable_type' => $taskableType,
                'taskable_id' => $id,
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * Human-readable list of all taskable names (for notifications, SMS).
     */
    public function taskableNamesLabel(): string
    {
        $this->loadMissing('taskableItems.taskable');

        $names = $this->taskableItems
            ->map(function (TractorTaskTaskable $item) {
                $m = $item->taskable;

                return $m ? ($m->name ?? $m->title ?? null) : null;
            })
            ->filter()
            ->values()
            ->all();

        return $names !== []
            ? implode(', ', $names)
            : (string) __('Unknown');
    }

    /**
     * Get the user that owns the TractorTask
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the task name (from operation name).
     *
     * @return string|null
     */
    public function getNameAttribute()
    {
        return $this->operation?->name;
    }

    /**
     * Scope a query to only include tasks for a specific date.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $date
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForDate($query, $date)
    {
        return $query->whereDate('date', $date);
    }

    /**
     * Scope a query to only include not started tasks.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotStarted($query)
    {
        return $query->where('status', 'not_started');
    }

    /**
     * Scope a query to only include tasks in progress.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope a query to only include stopped tasks.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStopped($query)
    {
        return $query->where('status', 'stopped');
    }

    /**
     * Scope a query to only include done tasks.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDone($query)
    {
        return $query->where('status', 'done');
    }

    /**
     * Scope a query to only include not done tasks.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotDone($query)
    {
        return $query->where('status', 'not_done');
    }

    /**
     * Scope a query to only include pending tasks (alias for not_started for backward compatibility).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'not_started');
    }

    /**
     * Scope a query to only include tasks that have started (alias for in_progress for backward compatibility).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStarted($query)
    {
        return $query->where('date', now()->format('Y-m-d'))
            ->whereTime('start_time', '<=', now()->format('H:i:s'))
            ->whereTime('end_time', '>=', now()->format('H:i:s'));
    }

    /**
     * Scope a query to only include finished tasks (alias for done for backward compatibility).
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFinished($query)
    {
        return $query->where('status', 'done');
    }
}
