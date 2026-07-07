<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_document_versions', function (Blueprint $table) {
            $table->char('reviewed_content_sha256', 64)->nullable();

            $table->index(
                'reviewed_content_sha256',
                'gen_doc_ver_review_hash_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('generated_document_versions', function (Blueprint $table) {
            $table->dropIndex('gen_doc_ver_review_hash_idx');
            $table->dropColumn('reviewed_content_sha256');
        });
    }
};
