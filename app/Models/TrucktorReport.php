<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrucktorReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'trucktor_id',
        'date',
        'start_time',
        'end_time',
        'operation_id',
        'field_id',
        'description',
        'created_by',
    ];

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
     * Get the trucktor that owns the TrucktorReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function trucktor()
    {
        return $this->belongsTo(Trucktor::class);
    }

    /**
     * Get the operation that owns the TrucktorReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function operation()
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Get the field that owns the TrucktorReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function field()
    {
        return $this->belongsTo(Field::class);
    }

    /**
     * Get the user that owns the TrucktorReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
