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
        Schema::create('account_deletions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('name', 160);
            $table->string('email', 190)->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->string('reason', 40)->nullable();   // key chosen in the app
            $table->text('comment')->nullable();        // optional free text
            $table->string('platform', 20)->nullable(); // android | ios | web
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('account_created_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_deletions');
    }
};
