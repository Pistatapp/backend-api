<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Tree extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'row_id',
        'name',
        'product',
        'location',
        'image',
        'unique_id',
        'qr_code',
    ];

    /**
     * Get the row that owns the tree.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function row()
    {
        return $this->belongsTo(Row::class);
    }
}
