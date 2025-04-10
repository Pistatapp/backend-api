<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Payment model for tracking Zarinpal payment transactions
 */
class Payment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable
     */
    protected $fillable = [
        'user_id',
        'amount',
        'description',
        'authority',
        'reference_id',
        'card_pan',
        'card_hash',
        'status',
        'payable_type',
        'payable_id',
    ];

    /**
     * Get the user who made the payment
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the payable model (polymorphic)
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check if the payment is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the payment is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the payment failed
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the payment was canceled by the user
     */
    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }
}
