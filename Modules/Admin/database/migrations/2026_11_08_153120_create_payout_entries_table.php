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
        // Every amount that moves a contractor's balance. Positive = we owe them, negative = they owe us.
        Schema::create('payout_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('contractor_id')->constrained('contractors')->cascadeOnDelete();
            $t->uuid('booking_id')->nullable()->index();
            $t->foreignUuid('payout_id')->nullable()->constrained('payouts')->nullOnDelete();
            $t->string('type', 12);                       // earning | adjustment
            $t->string('unique_key', 60)->nullable()->unique(); // "earning:{booking}" stops a second earning per booking
            $t->decimal('amount', 10, 2);                 // signed
            $t->decimal('gross', 10, 2)->nullable();      // what the customer paid, net of refunds, when this was worked out
            $t->decimal('commission_percent', 5, 2)->nullable();
            $t->string('currency', 3)->default('USD');
            $t->string('note', 300)->nullable();
            $t->timestamp('payable_at');
            $t->timestamps();
            $t->index(['contractor_id', 'payout_id', 'payable_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payout_entries');
    }
};
