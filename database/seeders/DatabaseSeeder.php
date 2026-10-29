<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        User::updateOrCreate(
            ['email' => 'wongnarin.s@msu.ac.th'],
            [
                'name' => 'วงศ์นรินทร์ สุขวิชัย',
                'password' => Hash::make('w123'),
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
            ],
        );

        $this->call([CommunitySeeder::class, ScoringCriteriaSeeder::class]);
    }
}
