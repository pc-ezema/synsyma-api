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
        Schema::create('booking_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('type', 30);                              // created | status | assigned | note
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('actor', 10);                             // customer | admin | system
            $table->string('note', 300)->nullable();
            $table->timestamps();
 
            $table->index(['booking_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_events');
    }
};
