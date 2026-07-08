<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_application_submission_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_application_id');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('client_reference', 100);
            $table->timestamp('submitted_at');
            $table->string('application_channel', 50);
            $table->string('external_reference')->nullable();
            $table->string('destination_url', 2048)->nullable();
            $table->unsignedBigInteger('generated_document_version_id')->nullable();
            $table->unsignedBigInteger('source_resume_version_id')->nullable();
            $table->unsignedInteger('document_version_number')->nullable();
            $table->string('document_filename')->nullable();
            $table->char('document_checksum_sha256', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                'job_application_id',
                'job_app_submit_conf_app_uq'
            );
            $table->index(
                ['submitted_at', 'job_application_id'],
                'job_app_submit_conf_time_idx'
            );
            $table->index('recorded_by', 'job_app_submit_conf_actor_idx');

            $table->foreign('job_application_id', 'job_app_submit_conf_app_fk')
                ->references('id')
                ->on('job_applications')
                ->cascadeOnDelete();
            $table->foreign('recorded_by', 'job_app_submit_conf_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_submission_confirmations');
    }
};
