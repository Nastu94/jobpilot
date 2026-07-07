<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('profile_sector', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('sector_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['profile_id', 'sector_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_sector');
        Schema::dropIfExists('sectors');
    }
};
