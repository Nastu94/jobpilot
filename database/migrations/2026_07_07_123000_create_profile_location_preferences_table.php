<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->boolean('willing_to_relocate')->default(false)->after('remote_preference');
        });

        Schema::create('profile_location_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('location');
            $table->string('country_code', 2)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['profile_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_location_preferences');

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('willing_to_relocate');
        });
    }
};
