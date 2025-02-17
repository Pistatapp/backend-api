<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TractorReport extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tractor_id',
        'date',
        'start_time',
        'end_time',
        'operation_id',
        'field_id',
        'description',
        'created_by',
    ];

    /**
     * The relationships that should always be loaded.
     *
     * @var array<string>
     */
    protected $with = ['operation', 'field', 'user'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, mixed>
     */
    protected function casts()
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * Get the tractor that owns the tractorReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tractor()
    {
        return $this->belongsTo(Tractor::class);
    }

    /**
     * Get the operation that owns the tractorReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Get the field that owns the tractorReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function field()
    {
        return $this->belongsTo(Field::class);
    }

    /**
     * Get the user that owns the tractorReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
