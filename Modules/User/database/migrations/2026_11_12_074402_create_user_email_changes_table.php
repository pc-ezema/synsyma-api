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
        Schema::create('user_email_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->uuid('admin_id')->nullable();            // who started it
            $t->string('old_email');
            $t->string('new_email');
            $t->string('reason', 300);
            $t->string('token_hash', 64)->unique();      // only a hash of the link is stored
            $t->string('status', 20)->default('pending'); // pending | confirmed | cancelled
            $t->timestamp('expires_at');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_email_changes');
    }
};
