<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table): void {
            $table->timestamp('submitted_context_captured_at')->nullable();
            $table->unsignedBigInteger('submitted_job_posting_id')->nullable();
            $table->string('submitted_job_title')->nullable();
            $table->string('submitted_company_name')->nullable();
            $table->string('submitted_job_source', 100)->nullable();
            $table->string('submitted_job_location')->nullable();
            $table->string('submitted_job_country_code', 2)->nullable();
            $table->string('submitted_job_remote_type', 50)->nullable();
            $table->string('submitted_job_employment_type', 100)->nullable();
            $table->string('submitted_job_seniority', 100)->nullable();
            $table->string('submitted_application_channel', 50)->nullable();
            $table->string('submitted_external_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table): void {
            $table->dropColumn([
                'submitted_context_captured_at',
                'submitted_job_posting_id',
                'submitted_job_title',
                'submitted_company_name',
                'submitted_job_source',
                'submitted_job_location',
                'submitted_job_country_code',
                'submitted_job_remote_type',
                'submitted_job_employment_type',
                'submitted_job_seniority',
                'submitted_application_channel',
                'submitted_external_reference',
            ]);
        });
    }
};
