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
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'profile_picture_url')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('profile_picture_url')->nullable()->after('password');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'profile_picture_url')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('profile_picture_url');
            });
        }
    }
};
