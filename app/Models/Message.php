<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'chat_room_id',
        'user_id',
        'message_type',
        'content',
        'file_path',
        'file_name',
        'file_size',
        'file_mime_type',
        'reply_to_message_id',
        'edited_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts()
    {
        return [
            'file_size' => 'integer',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * Get the chat room that owns the message.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function chatRoom()
    {
        return $this->belongsTo(ChatRoom::class);
    }

    /**
     * Get the user who sent the message.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the message this message is replying to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    /**
     * Get the read receipts for the message.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function reads()
    {
        return $this->hasMany(MessageRead::class);
    }

    /**
     * Get the deletions for the message.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function deletions()
    {
        return $this->hasMany(MessageDeletion::class);
    }

    /**
     * Check if the message is a text message.
     *
     * @return bool
     */
    public function isText()
    {
        return $this->message_type === 'text';
    }

    /**
     * Check if the message is a file message.
     *
     * @return bool
     */
    public function isFile()
    {
        return $this->message_type === 'file';
    }

    /**
     * Check if the message is a system message.
     *
     * @return bool
     */
    public function isSystem()
    {
        return $this->message_type === 'system';
    }

    /**
     * Check if the message has been edited.
     *
     * @return bool
     */
    public function isEdited()
    {
        return !is_null($this->edited_at);
    }

    /**
     * Check if message is deleted for a specific user.
     *
     * @param int $userId
     * @return bool
     */
    public function isDeletedForUser(int $userId): bool
    {
        return $this->deletions()
            ->where('deleted_by_user_id', $userId)
            ->where('deletion_type', 'for_me')
            ->exists();
    }

    /**
     * Check if message is deleted for everyone.
     *
     * @return bool
     */
    public function isDeletedForEveryone(): bool
    {
        return $this->deletions()
            ->where('deletion_type', 'for_everyone')
            ->exists();
    }
}

