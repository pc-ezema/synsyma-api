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
        Schema::create('app_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('type', 40);                  // booking_confirmed, quote_ready, ...
            $t->string('title', 120);
            $t->string('body', 400);
            $t->uuid('booking_id')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
 
            $t->index(['user_id', 'created_at']);
            $t->index(['user_id', 'read_at']);
            $t->index(['booking_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
