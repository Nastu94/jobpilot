<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 50);
            $table->string('name');
            $table->string('status', 50)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'document_type'], 'gen_doc_profile_type_idx');
            $table->index('status', 'gen_doc_status_idx');
        });

        Schema::create('generated_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generated_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_resume_version_id')
                ->nullable()
                ->constrained('resume_versions')
                ->nullOnDelete();
            $table->foreignId('match_analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('generation_method', 50)->default('manual');
            $table->string('generator_key', 100)->nullable();
            $table->string('generator_version', 50)->nullable();
            $table->char('input_hash', 64)->nullable();
            $table->string('content_format', 50)->default('plain_text');
            $table->longText('content')->nullable();
            $table->string('storage_disk', 50)->default('local');
            $table->string('storage_path')->nullable();
            $table->string('filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('review_status', 50)->default('pending');
            $table->boolean('contains_unverified_claims')->default(false);
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('change_summary')->nullable();
            $table->timestamps();

            $table->unique(
                ['generated_document_id', 'version_number'],
                'gen_doc_version_number_uq'
            );
            $table->index('review_status', 'gen_doc_version_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_document_versions');
        Schema::dropIfExists('generated_documents');
    }
};
