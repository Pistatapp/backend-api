<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;

class Ticket extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'status',
        'last_reply_by',
        'last_reply_at',
        'closed_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected function casts()
    {
        return [
            'last_reply_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the ticket.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the messages for the ticket.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at');
    }

    /**
     * Get the metadata for the ticket.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function metadata(): HasOne
    {
        return $this->hasOne(TicketMetadata::class);
    }

    /**
     * Scope a query to only include tickets for a specific user.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to filter by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to order by most recent.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderBy('last_reply_at', 'desc')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Scope a query to only include closed tickets.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    /**
     * Check if the ticket is closed.
     *
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Check if the ticket is waiting for response.
     *
     * @return bool
     */
    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    /**
     * Check if the ticket is answered.
     *
     * @return bool
     */
    public function isAnswered(): bool
    {
        return $this->status === 'answered';
    }

    /**
     * Reopen a closed ticket.
     *
     * @return void
     */
    public function reopen(): void
    {
        $this->update([
            'status' => 'waiting',
            'closed_at' => null,
        ]);
    }

    /**
     * Close the ticket.
     *
     * @return void
     */
    public function close(): void
    {
        $this->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }

    /**
     * Mark ticket as answered.
     *
     * @return void
     */
    public function markAsAnswered(): void
    {
        $this->update([
            'status' => 'answered',
        ]);
    }

    /**
     * Mark ticket as waiting.
     *
     * @return void
     */
    public function markAsWaiting(): void
    {
        $this->update([
            'status' => 'waiting',
        ]);
    }
}

