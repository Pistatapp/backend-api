<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Attachment extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'description',
        'verified',
        'user_id',
    ];

    /**
     * Get the model that the attachment belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function attachable()
    {
        return $this->morphTo();
    }

    /**
     * Get the user that owns the attachment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Register the media collections.
     *
     * @return void
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->singleFile();
    }

    /**
     * Get the media url of the attachment.
     *
     * @return string
     */
    public function getMediaUrlAttribute()
    {
        return $this->getFirstMediaUrl('attachments');
    }

    /**
     * Get the media size of the attachment.
     *
     * @return string
     */
    public function getMediaSizeAttribute()
    {
        return $this->getFirstMedia('attachments')->human_readable_size;
    }

    /**
     * Get the media extension of the attachment.
     *
     * @return string
     */
    public function getMediaExtensionAttribute()
    {
        return $this->getFirstMedia('attachments')->extension;
    }

    /**
     * Get the media name of the attachment.
     *
     * @return string
     */
    public function getMediaNameAttribute()
    {
        return $this->getFirstMedia('attachments')->file_name;
    }
}
