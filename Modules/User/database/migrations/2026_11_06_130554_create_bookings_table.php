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
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 12)->unique();               // SY-K7M2QX
            $table->uuid('user_id')->index();
            $table->foreignUuid('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignUuid('contractor_id')->nullable()->constrained('contractors')->nullOnDelete();
        
            $table->string('status', 20)->default('pending_payment')->index();
 
            // When (stored in UTC, with the customer's timezone so we can show local time)
            $table->dateTime('starts_at')->index();
            $table->string('timezone', 64);
 
            // Where (a copy, so later edits to the saved address never change an old booking)
            $table->char('country', 2);
            $table->string('address_line1', 160);
            $table->string('address_line2', 160)->nullable();
            $table->string('city', 80);
            $table->string('region', 80);
            $table->string('postal_code', 12);
            $table->string('instructions', 300)->nullable();
 
            // What (a copy of the service and its price at the time of booking)
            $table->string('service_name', 120);
            $table->string('category_name', 120)->nullable();
            $table->string('tier', 10)->nullable();
            $table->string('price_type', 10);
            $table->char('currency', 3)->default('USD');
            $table->decimal('base_price', 10, 2)->nullable();
            $table->unsignedTinyInteger('included_hours')->nullable();
            $table->decimal('additional_hour_price', 10, 2)->nullable();
 
            // Money (promo and tax are filled in by later phases)
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->string('promo_code', 40)->nullable();
            $table->unsignedTinyInteger('reschedule_count')->default(0);
            $table->unsignedTinyInteger('extra_hours')->default(0);
 
            $table->string('notes', 1000)->nullable();               // from the customer
            $table->text('admin_notes')->nullable();                 // private
 
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
 
            $table->index(['user_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
