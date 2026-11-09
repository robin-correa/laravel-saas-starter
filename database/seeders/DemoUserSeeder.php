<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        User::query()->firstOrCreate(['email' => 'owner@example.com'], [
            'name' => 'Owner',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);
    }
}
