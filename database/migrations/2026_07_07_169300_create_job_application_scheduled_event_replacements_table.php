<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_application_scheduled_event_replacements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_application_id');
            $table->unsignedBigInteger('previous_scheduled_event_id');
            $table->unsignedBigInteger('replacement_scheduled_event_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->string('client_reference', 100);
            $table->timestamp('changed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['job_application_id', 'client_reference'],
                'job_app_sched_repl_ref_uq'
            );
            $table->unique(
                'previous_scheduled_event_id',
                'job_app_sched_repl_prev_uq'
            );
            $table->unique(
                'replacement_scheduled_event_id',
                'job_app_sched_repl_new_uq'
            );
            $table->index(
                ['job_application_id', 'changed_at'],
                'job_app_sched_repl_app_idx'
            );
            $table->index('changed_by', 'job_app_sched_repl_actor_idx');

            $table->foreign('job_application_id', 'job_app_sched_repl_app_fk')
                ->references('id')
                ->on('job_applications')
                ->cascadeOnDelete();
            $table->foreign('previous_scheduled_event_id', 'job_app_sched_repl_prev_fk')
                ->references('id')
                ->on('job_application_scheduled_events')
                ->cascadeOnDelete();
            $table->foreign('replacement_scheduled_event_id', 'job_app_sched_repl_new_fk')
                ->references('id')
                ->on('job_application_scheduled_events')
                ->cascadeOnDelete();
            $table->foreign('changed_by', 'job_app_sched_repl_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_scheduled_event_replacements');
    }
};
