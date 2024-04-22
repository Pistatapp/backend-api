<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
    ];

    /**
     * Get the user that owns the Operation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the operation reports for the operation.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function operationReports()
    {
        // return $this->hasMany(OperationReport::class);
    }
}
