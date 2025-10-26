<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite no soporta MODIFY COLUMN, así que usamos el enfoque de recrear la tabla
        if (DB::connection()->getDriverName() === 'sqlite') {
            // En SQLite, simplemente no hacemos nada porque el Model ya maneja los valores posibles
            // y la columna ya existe como string
            return;
        }
        
        // MySQL: Modificar el ENUM para agregar 'active' entre 'waiting' y 'playing'
        DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('waiting', 'active', 'playing', 'finished') NOT NULL DEFAULT 'waiting'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        
        // Revertir: Eliminar 'active' del ENUM
        // IMPORTANTE: Esto fallará si hay registros con status='active'
        DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('waiting', 'playing', 'finished') NOT NULL DEFAULT 'waiting'");
    }
};
