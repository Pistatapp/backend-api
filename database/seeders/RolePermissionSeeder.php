<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\PermissionSeeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
        ]);

        $rolePermissions = file_get_contents(public_path('json/role_permissions.json'));

        $rolePermissions = json_decode($rolePermissions, true);

        Role::all()->each(function ($role) use ($rolePermissions) {
            $permissions = array_key_exists($role->name, $rolePermissions)
                ? array_keys($rolePermissions[$role->name])
                : [];

            $role->syncPermissions($permissions);
        });
    }
}
