<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_application_scheduled_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_application_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('client_reference', 100)->nullable();
            $table->string('event_type', 50);
            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('location')->nullable();
            $table->string('meeting_url', 2048)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status', 20)->default('planned');
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(
                ['job_application_id', 'starts_at'],
                'job_app_sched_start_idx'
            );
            $table->index(
                ['job_application_id', 'status', 'starts_at'],
                'job_app_sched_status_idx'
            );
            $table->unique(
                ['job_application_id', 'client_reference'],
                'job_app_sched_ref_uq'
            );
            $table->index('created_by', 'job_app_sched_creator_idx');
            $table->index('resolved_by', 'job_app_sched_resolver_idx');
            $table->foreign('job_application_id', 'job_app_sched_app_fk')
                ->references('id')
                ->on('job_applications')
                ->cascadeOnDelete();
            $table->foreign('created_by', 'job_app_sched_creator_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('resolved_by', 'job_app_sched_resolver_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_scheduled_events');
    }
};
