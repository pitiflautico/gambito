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
        // Modificar el ENUM para agregar 'active' entre 'waiting' y 'playing'
        DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('waiting', 'active', 'playing', 'finished') NOT NULL DEFAULT 'waiting'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revertir: Eliminar 'active' del ENUM
        // IMPORTANTE: Esto fallará si hay registros con status='active'
        DB::statement("ALTER TABLE rooms MODIFY COLUMN status ENUM('waiting', 'playing', 'finished') NOT NULL DEFAULT 'waiting'");
    }
};
