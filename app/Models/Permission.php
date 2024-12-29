<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    /**
     * The attributes that should be appended to the model.
     *
     * @var array<string>
     */
    protected $appends = ['persian_name'];

    /**
     * The attributes with default values.
     *
     * @var array<string>
     */
    protected $attributes = [
        'guard_name' => 'web',
    ];

    /**
     * Get the Persian name of the permission.
     *
     * @return string
     */
    public function getPersianNameAttribute()
    {
        return __($this->name);
    }
}
