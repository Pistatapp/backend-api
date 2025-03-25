<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Tree extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'row_id',
        'name',
        'location',
        'image',
        'unique_id',
        'qr_code',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'location' => 'array',
        ];
    }

    /**
     * Get the row that owns the tree.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function row()
    {
        return $this->belongsTo(Row::class);
    }

    /**
     * Get the attachments for the tree.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * Get the treatment for the tree.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function treatments()
    {
        return $this->morphMany(Treatment::class, 'treatable');
    }

    /**
     * Get the reports for the tree.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function reports()
    {
        return $this->morphMany(FarmReport::class, 'reportable');
    }
}
