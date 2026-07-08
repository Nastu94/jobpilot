<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_application_interactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_application_id');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('client_reference', 100)->nullable();
            $table->string('interaction_type', 50);
            $table->string('direction', 20);
            $table->string('subject')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['job_application_id', 'occurred_at'],
                'job_app_interaction_time_idx'
            );
            $table->unique(
                ['job_application_id', 'client_reference'],
                'job_app_interaction_ref_uq'
            );
            $table->index('recorded_by', 'job_app_interaction_actor_idx');
            $table->foreign('job_application_id', 'job_app_interaction_app_fk')
                ->references('id')
                ->on('job_applications')
                ->cascadeOnDelete();
            $table->foreign('recorded_by', 'job_app_interaction_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_interactions');
    }
};
