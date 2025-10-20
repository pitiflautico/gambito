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
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique(); // Debe coincidir con el nombre de la carpeta en games/{slug}/
            $table->text('description');
            $table->string('path'); // Ruta al folder del juego: games/{slug}
            $table->json('metadata')->nullable(); // Metadata opcional cacheada (minPlayers, maxPlayers, etc.) - se carga desde config.json del módulo
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Índices
            $table->index('slug');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
