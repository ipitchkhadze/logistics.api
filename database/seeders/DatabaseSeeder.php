<?php

namespace Database\Seeders;

use App\Models\Slot;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Slot::query()->create(['capacity' => 10, 'remaining' => 10]);
        Slot::query()->create(['capacity' => 5, 'remaining' => 5]);
        Slot::query()->create(['capacity' => 1, 'remaining' => 1]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
