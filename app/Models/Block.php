<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Block extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_id',
        'name',
        'coordinates',
    ];

    protected $casts = [
        'coordinates' => 'array',
    ];

    public function field()
    {
        return $this->belongsTo(Field::class);
    }
}
