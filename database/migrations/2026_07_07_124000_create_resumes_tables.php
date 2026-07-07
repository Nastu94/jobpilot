<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['profile_id', 'is_primary']);
        });

        Schema::create('resume_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resume_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('source', 50)->default('upload');
            $table->string('original_filename');
            $table->string('storage_disk', 50)->default('local');
            $table->string('storage_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('processing_status', 50)->default('pending');
            $table->longText('extracted_text')->nullable();
            $table->timestamps();

            $table->unique(['resume_id', 'version_number']);
            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_versions');
        Schema::dropIfExists('resumes');
    }
};
