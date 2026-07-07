<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('match_analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('generated_document_version_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('operation_type', 100);
            $table->string('provider', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('prompt_template_key', 100)->nullable();
            $table->string('prompt_template_version', 50)->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('external_request_id')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->char('response_hash', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('cost_micros')->nullable();
            $table->char('cost_currency', 3)->nullable();
            $table->boolean('payloads_stored')->default(false);
            $table->json('metadata')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'operation_type'], 'ai_op_profile_type_idx');
            $table->index('status', 'ai_op_status_idx');
            $table->index('started_at', 'ai_op_started_idx');
            $table->index(['provider', 'model'], 'ai_op_provider_model_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_operations');
    }
};
