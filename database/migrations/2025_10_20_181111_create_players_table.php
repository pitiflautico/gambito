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
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained()->onDelete('cascade'); // Partida en la que participa
            $table->string('name'); // Nombre/apodo del jugador (no registrado)
            $table->string('role')->nullable(); // Rol en el juego (ej: 'drawer', 'guesser' en Pictionary)
            $table->integer('score')->default(0); // Puntuación actual
            $table->boolean('is_connected')->default(true); // Estado de conexión
            $table->timestamp('last_ping')->nullable(); // Última actividad detectada (heartbeat)
            $table->timestamps();

            // Índices
            $table->index('match_id');
            $table->index(['match_id', 'is_connected']); // Para consultas de jugadores activos
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
