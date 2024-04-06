<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Attachment extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'description',
        'verified',
        'user_id',
    ];

    public function attachable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->singleFile();
    }

    public function getMediaUrlAttribute()
    {
        return $this->getFirstMediaUrl('attachments');
    }

    public function getMediaSizeAttribute()
    {
        return $this->getFirstMedia('attachments')->human_readable_size;
    }

    public function getMediaExtensionAttribute()
    {
        return $this->getFirstMedia('attachments')->extension;
    }

    public function getMediaNameAttribute()
    {
        return $this->getFirstMedia('attachments')->file_name;
    }
}
