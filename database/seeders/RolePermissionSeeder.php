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

        $rolePermissions = file_get_contents(storage_path('app/json/role_permissions.json'));

        $rolePermissions = json_decode($rolePermissions, true);

        Role::all()->each(function ($role) use ($rolePermissions) {
            $permissions = array_key_exists($role->name, $rolePermissions)
                ? array_keys($rolePermissions[$role->name])
                : [];

            $role->syncPermissions($permissions);
        });

        if (app()->environment('local')) {
            $localRootUser = User::where('mobile', '09107529334')->first();

            if ($localRootUser) {
                $localRootUser->assignRole('root');
            }

            $localUsers = User::whereNot('mobile', '09107529334')->get();

            $localUsers->each(function ($user) {
                $user->assignRole('admin');
            });
        } else {
            $rootUser = User::where('mobile', '09195065248')->first();

            if ($rootUser) {
                $rootUser->assignRole('root');
            }

            $users = User::whereNot('mobile', '09195065248')->get();

            $users->each(function ($user) {
                $user->assignRole('admin');
            });
        }
    }
}
