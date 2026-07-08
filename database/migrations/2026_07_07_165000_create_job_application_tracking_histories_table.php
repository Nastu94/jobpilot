<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_application_tracking_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('change_source', 50);
            $table->string('previous_application_channel', 50)->nullable();
            $table->string('application_channel', 50)->nullable();
            $table->string('previous_external_reference')->nullable();
            $table->string('external_reference')->nullable();
            $table->timestamp('previous_next_action_at')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->text('previous_notes')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(
                ['job_application_id', 'changed_at'],
                'job_app_track_changed_idx'
            );
            $table->index('changed_by', 'job_app_track_actor_idx');
            $table->foreign('changed_by', 'job_app_track_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_tracking_histories');
    }
};
