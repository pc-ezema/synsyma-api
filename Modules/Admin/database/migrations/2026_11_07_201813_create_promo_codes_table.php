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
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();                 // stored UPPERCASE
            $table->string('description', 160)->nullable();       // shown to the customer when applied: "10% off your first clean"
            $table->string('type', 10);                           // percent | fixed
            $table->decimal('value', 10, 2);                      // 10 = 10% or $10.00
            $table->decimal('max_discount', 10, 2)->nullable();   // cap for percent codes
            $table->decimal('min_subtotal', 10, 2)->nullable();   // minimum spend
            $table->date('starts_on')->nullable();                // UTC dates, both inclusive
            $table->date('expires_on')->nullable();
            $table->unsignedInteger('max_uses')->nullable();      // across all customers
            $table->unsignedSmallInteger('max_uses_per_user')->default(1);
            $table->boolean('first_booking_only')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
