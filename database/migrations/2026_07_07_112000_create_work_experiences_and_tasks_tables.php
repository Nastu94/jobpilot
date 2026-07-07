<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('company_name');
            $table->string('job_title');
            $table->string('location')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'start_date']);
        });

        Schema::create('work_experience_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_experience_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->text('description');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['work_experience_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_experience_tasks');
        Schema::dropIfExists('work_experiences');
    }
};
