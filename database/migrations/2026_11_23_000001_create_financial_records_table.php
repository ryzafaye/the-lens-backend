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
        Schema::create('financial_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->string('file_name', 255);
            $table->text('file_url');
            $table->string('public_id', 255)->nullable();
            $table->string('resource_type', 50)->default('raw');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestampsTz();

            $table->index('uploader_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_records');
    }
};
