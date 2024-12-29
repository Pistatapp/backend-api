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
     * The attributes with default values.
     *
     * @var array<string>
     */
    protected $attributes = [
        'guard_name' => 'web',
    ];

    /**
     * Get the Persian name of the role.
     *
     * @return string
     */
    public function getPersianNameAttribute()
    {
        return __($this->name);
    }
}
