<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_document_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('reviewed_by')->nullable();

            $table->index('reviewed_by', 'gen_doc_ver_reviewer_idx');
            $table->foreign('reviewed_by', 'gen_doc_ver_reviewer_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_document_versions', function (Blueprint $table) {
            $table->dropForeign('gen_doc_ver_reviewer_fk');
            $table->dropIndex('gen_doc_ver_reviewer_idx');
            $table->dropColumn('reviewed_by');
        });
    }
};
