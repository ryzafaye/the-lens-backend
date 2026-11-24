<?php

declare(strict_types=1);

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
        Schema::table('article_images', function (Blueprint $table) {
            if (Schema::hasColumn('article_images', 'caption')) {
                $table->dropColumn('caption');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_images', function (Blueprint $table) {
            if (!Schema::hasColumn('article_images', 'caption')) {
                $table->text('caption')->nullable()->after('title');
            }
        });
    }
};
