<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('website_url')->nullable();
            $table->string('headquarters_location')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('company_name')->nullable();
            $table->string('source', 50)->default('manual');
            $table->string('external_id')->nullable();
            $table->text('source_url')->nullable();
            $table->string('location')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('remote_type', 50)->nullable();
            $table->string('employment_type', 50)->nullable();
            $table->string('seniority', 50)->nullable();
            $table->unsignedInteger('salary_min')->nullable();
            $table->unsignedInteger('salary_max')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('status', 50)->default('active');
            $table->string('processing_status', 50)->default('pending');
            $table->longText('description')->nullable();
            $table->longText('raw_content')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
            $table->index(['source', 'external_id']);
            $table->index('processing_status');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
        Schema::dropIfExists('companies');
    }
};
