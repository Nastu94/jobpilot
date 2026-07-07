<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('issuing_organization');
            $table->string('credential_id')->nullable();
            $table->string('credential_url')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('does_not_expire')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'issue_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certifications');
    }
};
