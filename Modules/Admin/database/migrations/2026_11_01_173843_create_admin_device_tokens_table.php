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
        Schema::create('admin_device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->string('platform', 10)->default('web');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('admin_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_device_tokens');
    }
};
