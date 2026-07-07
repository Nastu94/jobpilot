<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->string('category', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('skill_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias')->unique();
            $table->timestamps();
        });

        Schema::create('profile_skill', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('proficiency_level', 50)->nullable();
            $table->decimal('years_experience', 4, 1)->nullable();
            $table->string('source', 50)->nullable();
            $table->boolean('is_approved')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_skill');
        Schema::dropIfExists('skill_aliases');
        Schema::dropIfExists('skills');
    }
};
