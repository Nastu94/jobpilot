<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_posting_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('software_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('language_id')->nullable()->constrained()->nullOnDelete();
            $table->string('requirement_type', 50);
            $table->string('importance', 50)->default('required');
            $table->string('label');
            $table->string('normalized_label')->nullable();
            $table->string('proficiency_level', 50)->nullable();
            $table->decimal('min_years', 4, 1)->nullable();
            $table->string('source', 50)->default('manual');
            $table->string('review_status', 50)->default('pending');
            $table->unsignedSmallInteger('confidence_bps')->nullable();
            $table->text('evidence')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(
                ['job_posting_id', 'review_status'],
                'job_req_posting_review_idx'
            );
            $table->index(
                ['job_posting_id', 'requirement_type'],
                'job_req_posting_type_idx'
            );
            $table->index(
                ['source', 'review_status'],
                'job_req_source_review_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_posting_requirements');
    }
};
