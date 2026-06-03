<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CreateUserProfile extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::whereDoesntHave('profile')->each(function ($user) {
            $user->profile()->create();
        });
    }
}
