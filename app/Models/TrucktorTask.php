<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrucktorTask extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'trucktor_id',
        'operation_id',
        'field_ids',
        'name',
        'start_date',
        'end_date',
        'status',
        'description',
        'created_by',
    ];

    /**
     * The relationships that should always be loaded.
     *
     * @var array<string>
     */
    protected $with = ['creator', 'operation'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected function casts()
    {
        return [
            'field_ids' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'created_by' => 'integer',
        ];
    }

    /**
     * Get the trucktor that owns the TrucktorTask
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function trucktor()
    {
        return $this->belongsTo(Trucktor::class);
    }

    /**
     * Get the operation that owns the TrucktorTask
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Get the user that owns the TrucktorTask
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
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
        return $query->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date);
    }
}
