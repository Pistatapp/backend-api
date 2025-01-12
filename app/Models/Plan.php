<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravelcm\Subscriptions\Models\Plan as ModelsPlan;

class Plan extends ModelsPlan
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'signup_fee',
        'currency',
        'interval',
        'interval_count',
        'trial_period_days',
        'sort_order',
        'is_active',
    ];
}
