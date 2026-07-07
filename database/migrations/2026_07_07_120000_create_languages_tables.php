<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 10)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('profile_language', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('proficiency_level', 50)->nullable();
            $table->boolean('is_native')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_language');
        Schema::dropIfExists('languages');
    }
};
