<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /**
     * The attributes that should be appended to the model.
     *
     * @var array<string>
     */
    protected $appends = ['persian_name'];

    /**
     * Get the Persian name of the role.
     *
     * @return string
     */
    public function getPersianNameAttribute()
    {
        return __('roles.' . $this->name);
    }
}
