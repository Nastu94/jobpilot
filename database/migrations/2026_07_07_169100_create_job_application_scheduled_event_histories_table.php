<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_application_scheduled_event_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scheduled_event_id');
            $table->unsignedBigInteger('job_application_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('status', 20);
            $table->timestamp('changed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['scheduled_event_id', 'changed_at'],
                'job_app_sched_hist_event_idx'
            );
            $table->index(
                ['job_application_id', 'changed_at'],
                'job_app_sched_hist_app_idx'
            );
            $table->index('changed_by', 'job_app_sched_hist_actor_idx');
            $table->foreign('scheduled_event_id', 'job_app_sched_hist_event_fk')
                ->references('id')
                ->on('job_application_scheduled_events')
                ->cascadeOnDelete();
            $table->foreign('job_application_id', 'job_app_sched_hist_app_fk')
                ->references('id')
                ->on('job_applications')
                ->cascadeOnDelete();
            $table->foreign('changed_by', 'job_app_sched_hist_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_scheduled_event_histories');
    }
};
