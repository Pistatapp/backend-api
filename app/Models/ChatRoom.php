<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatRoom extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'farm_id',
        'type',
        'name',
        'created_by',
        'last_message_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts()
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Get the farm that owns the chat room.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the user who created the chat room.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the users that belong to the chat room.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'chat_room_user')
            ->withPivot('joined_at', 'left_at', 'last_read_at', 'is_muted')
            ->withTimestamps();
    }

    /**
     * Get the active users (not left) in the chat room.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function activeUsers()
    {
        return $this->belongsToMany(User::class, 'chat_room_user')
            ->wherePivotNull('left_at')
            ->withPivot('joined_at', 'left_at', 'last_read_at', 'is_muted')
            ->withTimestamps();
    }

    /**
     * Get the messages for the chat room.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Get the last message for the chat room.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function lastMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Check if the chat room is a private chat.
     *
     * @return bool
     */
    public function isPrivate()
    {
        return $this->type === 'private';
    }

    /**
     * Check if the chat room is a group chat.
     *
     * @return bool
     */
    public function isGroup()
    {
        return $this->type === 'group';
    }
}

