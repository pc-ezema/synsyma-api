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
        Schema::create('booking_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('free_cancel_hours')->default(24);      // more than this before the start: free
            $table->unsignedSmallInteger('late_cancel_hours')->default(4);       // between the two: a fee; under this: no refund
            $table->unsignedTinyInteger('late_cancel_fee_percent')->default(50);
            $table->unsignedTinyInteger('free_reschedules')->default(1);         // used in the reschedule step
            $table->unsignedTinyInteger('max_extra_hours')->default(4);          // used in the add-hours step
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_policies');
    }
};
