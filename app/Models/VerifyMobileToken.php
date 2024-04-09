<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerifyMobileToken extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    protected $primaryKey = 'mobile';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Determine if the token is expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->created_at->addMinutes(2)->isPast();
    }
}
