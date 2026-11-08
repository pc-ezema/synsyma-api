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
        // A payment made to a contractor (you send the money yourself, then record it here).
        Schema::create('payouts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('contractor_id')->constrained('contractors')->cascadeOnDelete();
            $t->decimal('amount', 10, 2);
            $t->string('currency', 3)->default('USD');
            $t->string('reference', 120);                 // bank / transfer reference typed by the admin
            $t->string('note', 300)->nullable();
            $t->uuid('paid_by')->nullable();
            $t->string('paid_by_name')->nullable();
            $t->timestamp('paid_at');
            $t->timestamps();
            $t->index(['contractor_id', 'paid_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
