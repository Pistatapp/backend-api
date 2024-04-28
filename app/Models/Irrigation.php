<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Irrigation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'labor_id',
        'field_id',
        'date',
        'start_time',
        'end_time',
        'valves',
        'created_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts() {
        return [
            'valves' => 'array',
            'date' => 'date',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
        ];
    }

    /**
     * The relationships that should always be loaded.
     *
     * @var array<string>
     */
    protected $with = ['field', 'labor', 'creator'];

    public function getDurationAttribute()
    {
        return $this->start_time->diff($this->end_time)->format('%H:%I');
    }

    /**
     * Get the field that owns the Irrigation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function field()
    {
        return $this->belongsTo(Field::class);
    }

    /**
     * Get the labor that owns the Irrigation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function labor()
    {
        return $this->belongsTo(Labor::class);
    }

    /**
     * Get the user that created the Irrigation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get all of the valves for the Irrigation
     *
     * @return \Illuminate\Support\Collection
     */
    public function valves()
    {
        return Valve::whereIn('id', $this->valves ?? [])->get();
    }
}
