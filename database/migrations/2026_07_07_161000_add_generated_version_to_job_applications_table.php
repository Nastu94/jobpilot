<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('generated_document_version_id')->nullable();

            $table->index(
                'generated_document_version_id',
                'job_app_gen_ver_idx'
            );
            $table->foreign(
                'generated_document_version_id',
                'job_app_gen_ver_fk'
            )
                ->references('id')
                ->on('generated_document_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropForeign('job_app_gen_ver_fk');
            $table->dropIndex('job_app_gen_ver_idx');
            $table->dropColumn('generated_document_version_id');
        });
    }
};
