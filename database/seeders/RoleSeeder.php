<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            ['name' => 'root'],
            ['name' => 'super_admin'],
            ['name' => 'admin'],
            ['name' => 'operator'],
            ['name' => 'viewer'],
            ['name' => 'consultant'],
            ['name' => 'inspector']
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate($role);
        }
    }
}
