<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 6)->unique(); // Código de sala (ej: ABC123)
            $table->foreignId('game_id')->constrained()->onDelete('cascade'); // Juego seleccionado
            $table->foreignId('master_id')->constrained('users')->onDelete('cascade'); // Usuario que creó la sala
            $table->enum('status', ['waiting', 'playing', 'finished'])->default('waiting'); // Estado de la sala
            $table->json('settings')->nullable(); // Configuración personalizada (ej: rondas, dificultad)
            $table->timestamps();

            // Índices
            $table->index('code');
            $table->index('status');
            $table->index(['game_id', 'status']); // Para consultas de salas activas por juego
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
