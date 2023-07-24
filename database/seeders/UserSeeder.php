<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        User::create([
            'name' => 'Calima Solutions',
            'email' => 'dev@calimasolutions.com',
            'email_verified_at' => now(),
            'password' => Hash::make('123456789'),
            'role' => 'admin'
        ]);
        User::create([
            'name' => 'Normal User',
            'email' => 'tester@gmail.com',
            'email_verified_at' => now(),
            'password' => Hash::make('123456789'),
        ]);
    }
}
