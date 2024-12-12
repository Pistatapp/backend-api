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
     * Get the Persian name of the permission.
     *
     * @return string
     */
    public function getPersianNameAttribute()
    {
        return __('permissions.' . $this->name);
    }
}
