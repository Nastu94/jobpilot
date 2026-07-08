<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('submitted_generated_document_version_id')->nullable();
            $table->unsignedBigInteger('submitted_source_resume_version_id')->nullable();
            $table->unsignedInteger('submitted_document_version_number')->nullable();
            $table->string('submitted_document_filename')->nullable();
            $table->string('submitted_document_mime_type', 100)->nullable();
            $table->unsignedBigInteger('submitted_document_file_size')->nullable();
            $table->char('submitted_document_checksum_sha256', 64)->nullable();
            $table->char('submitted_document_content_sha256', 64)->nullable();
            $table->string('submitted_document_storage_disk', 50)->nullable();
            $table->string('submitted_document_storage_path')->nullable();
            $table->string('submitted_document_generator_key', 100)->nullable();
            $table->string('submitted_document_generator_version', 50)->nullable();
            $table->timestamp('submitted_document_reviewed_at')->nullable();

            $table->index(
                'submitted_document_checksum_sha256',
                'job_app_sub_doc_hash_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropIndex('job_app_sub_doc_hash_idx');
            $table->dropColumn([
                'submitted_generated_document_version_id',
                'submitted_source_resume_version_id',
                'submitted_document_version_number',
                'submitted_document_filename',
                'submitted_document_mime_type',
                'submitted_document_file_size',
                'submitted_document_checksum_sha256',
                'submitted_document_content_sha256',
                'submitted_document_storage_disk',
                'submitted_document_storage_path',
                'submitted_document_generator_key',
                'submitted_document_generator_version',
                'submitted_document_reviewed_at',
            ]);
        });
    }
};
