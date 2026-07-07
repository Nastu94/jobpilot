<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resume_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title');
            $table->string('company_name')->nullable();
            $table->string('status', 50)->default('draft');
            $table->string('application_channel', 50)->nullable();
            $table->string('external_reference')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'status']);
            $table->index('applied_at');
            $table->index('next_action_at');
        });

        Schema::create('job_application_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained()->cascadeOnDelete();
            $table->string('status', 50);
            $table->timestamp('changed_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['job_application_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_application_status_histories');
        Schema::dropIfExists('job_applications');
    }
};
