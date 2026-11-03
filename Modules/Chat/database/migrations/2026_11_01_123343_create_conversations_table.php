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
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status', 20)->default('open');            // open | closed
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 160)->nullable();
            $table->string('last_message_sender', 10)->nullable();    // user | admin
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
