<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('Agricultural Researcher')->after('name');
            $table->string('organization')->default('University Research Farm')->after('role');
            $table->string('region')->default('CALABARZON')->after('organization');
            $table->string('phone')->nullable()->after('email');
            $table->string('avatar_url')->nullable()->after('region');
            $table->json('preferences')->nullable()->after('avatar_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'organization', 'region', 'phone', 'avatar_url', 'preferences']);
        });
    }
};
