<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_application_document_access_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_application_id');
            $table->unsignedBigInteger('accessed_by')->nullable();
            $table->string('document_source', 50);
            $table->unsignedBigInteger('generated_document_version_id')->nullable();
            $table->unsignedBigInteger('source_resume_version_id')->nullable();
            $table->string('filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum_sha256', 64);
            $table->string('storage_disk', 50);
            $table->string('storage_path');
            $table->timestamp('accessed_at');
            $table->timestamps();

            $table->index(
                ['job_application_id', 'accessed_at'],
                'job_app_doc_accessed_idx'
            );
            $table->index('accessed_by', 'job_app_doc_access_actor_idx');
            $table->foreign('job_application_id', 'job_app_doc_access_app_fk')
                ->references('id')
                ->on('job_applications')
                ->cascadeOnDelete();
            $table->foreign('accessed_by', 'job_app_doc_access_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_document_access_histories');
    }
};
