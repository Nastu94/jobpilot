<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_posting_requirements', function (Blueprint $table) {
            $table->string('proposed_requirement_type', 50)->nullable();
            $table->string('proposed_importance', 50)->nullable();
            $table->string('proposed_label')->nullable();
            $table->string('proposed_normalized_label')->nullable();
            $table->string('proposed_proficiency_level', 50)->nullable();
            $table->decimal('proposed_min_years', 4, 1)->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->index('reviewed_by', 'job_req_reviewer_idx');
            $table->foreign('reviewed_by', 'job_req_reviewer_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        DB::table('job_posting_requirements')
            ->where('source', 'ai')
            ->update([
                'proposed_requirement_type' => DB::raw('requirement_type'),
                'proposed_importance' => DB::raw('importance'),
                'proposed_label' => DB::raw('label'),
                'proposed_normalized_label' => DB::raw('normalized_label'),
                'proposed_proficiency_level' => DB::raw('proficiency_level'),
                'proposed_min_years' => DB::raw('min_years'),
            ]);
    }

    public function down(): void
    {
        Schema::table('job_posting_requirements', function (Blueprint $table) {
            $table->dropForeign('job_req_reviewer_fk');
            $table->dropIndex('job_req_reviewer_idx');
            $table->dropColumn([
                'proposed_requirement_type',
                'proposed_importance',
                'proposed_label',
                'proposed_normalized_label',
                'proposed_proficiency_level',
                'proposed_min_years',
                'reviewed_by',
                'reviewed_at',
                'review_notes',
            ]);
        });
    }
};