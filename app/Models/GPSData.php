<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GPSData extends Model
{
    use HasFactory;

    protected $table = 'g_p_s_data';

    protected $fillable = [
        'data'
    ];
}
