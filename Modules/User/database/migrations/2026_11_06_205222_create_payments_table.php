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
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->uuid('user_id')->index();
 
            $table->string('provider', 20)->default('stripe');
            $table->string('purpose', 20)->default('booking'); // booking | extra_hours (phase 4)
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            // requires_payment | succeeded | failed | refunded | partially_refunded
            $table->string('status', 24)->default('requires_payment')->index();
 
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_charge_id')->nullable();
            $table->string('paypal_order_id')->nullable()->unique();
            $table->string('paypal_capture_id')->nullable();
            $table->string('return_url', 500)->nullable();
            $table->string('card_brand', 20)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('failure_message')->nullable();
            $table->decimal('refunded_total', 10, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->unsignedTinyInteger('hours')->nullable();
            $table->timestamps();
        });
 
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'stripe_customer_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('stripe_customer_id')->nullable()->index();
            });
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
