<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_application_status_histories', function (Blueprint $table) {
            $table->string('from_status', 50)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();

            $table->index('changed_by', 'job_app_hist_actor_idx');
            $table->foreign('changed_by', 'job_app_hist_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_application_status_histories', function (Blueprint $table) {
            $table->dropForeign('job_app_hist_actor_fk');
            $table->dropIndex('job_app_hist_actor_idx');
            $table->dropColumn(['from_status', 'changed_by']);
        });
    }
};
