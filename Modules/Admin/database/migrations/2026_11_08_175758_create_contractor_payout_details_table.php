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
        Schema::create('contractor_payout_details', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('contractor_id')->unique()->constrained('contractors')->cascadeOnDelete();
            $t->string('method', 20);                 // bank_transfer | etransfer | paypal | other
            $t->string('country', 2)->nullable();     // CA | US | GB, bank transfers only
            $t->string('holder_name', 120);
            $t->string('bank_name', 120)->nullable();
            $t->text('details');                      // encrypted JSON: account numbers, emails, instructions
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractor_payout_details');
    }
};
