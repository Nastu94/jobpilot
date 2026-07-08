<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_application_document_version_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->unsignedBigInteger('generated_document_id');
            $table->unsignedBigInteger('previous_generated_document_version_id')->nullable();
            $table->unsignedBigInteger('generated_document_version_id');
            $table->unsignedBigInteger('previous_resume_version_id')->nullable();
            $table->unsignedBigInteger('resume_version_id');
            $table->unsignedInteger('previous_version_number')->nullable();
            $table->unsignedInteger('version_number');
            $table->char('previous_checksum_sha256', 64)->nullable();
            $table->char('checksum_sha256', 64);
            $table->char('previous_reviewed_content_sha256', 64)->nullable();
            $table->char('reviewed_content_sha256', 64);
            $table->timestamp('changed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(
                ['job_application_id', 'changed_at'],
                'job_app_doc_sel_changed_idx'
            );
            $table->index('changed_by', 'job_app_doc_sel_actor_idx');
            $table->foreign('changed_by', 'job_app_doc_sel_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_document_version_histories');
    }
};
