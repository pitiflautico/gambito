<?php

namespace Database\Seeders;

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
        // Crear usuario administrador
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@gambito.com',
            'role' => 'admin',
            'password' => bcrypt('password'),
        ]);

        // Crear usuario de prueba
        User::factory()->create([
            'name' => 'Usuario Test',
            'email' => 'user@gambito.com',
            'role' => 'user',
            'password' => bcrypt('password'),
        ]);
    }
}
