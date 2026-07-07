<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resume_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ruleset_key', 100);
            $table->string('ruleset_version', 50);
            $table->string('status', 50)->default('pending');
            $table->unsignedSmallInteger('score_bps')->nullable();
            $table->char('input_hash', 64)->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'job_posting_id'], 'match_analysis_profile_job_idx');
            $table->index(['ruleset_key', 'ruleset_version'], 'match_analysis_ruleset_idx');
            $table->index('status', 'match_analysis_status_idx');
            $table->index('calculated_at', 'match_analysis_calc_idx');
        });

        Schema::create('match_factors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('label');
            $table->string('category', 50)->nullable();
            $table->unsignedSmallInteger('weight_bps')->default(0);
            $table->unsignedSmallInteger('score_bps')->nullable();
            $table->integer('contribution_bps')->default(0);
            $table->string('outcome', 50)->nullable();
            $table->text('explanation')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['match_analysis_id', 'key'], 'match_factor_analysis_key_uq');
            $table->index(['match_analysis_id', 'position'], 'match_factor_order_idx');
        });

        Schema::create('match_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_factor_id')->constrained()->cascadeOnDelete();
            $table->string('evidence_type', 50);
            $table->string('label');
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference')->nullable();
            $table->text('details')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['match_factor_id', 'position'], 'match_evidence_order_idx');
            $table->index(['source_type', 'source_id'], 'match_evidence_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_evidences');
        Schema::dropIfExists('match_factors');
        Schema::dropIfExists('match_analyses');
    }
};
