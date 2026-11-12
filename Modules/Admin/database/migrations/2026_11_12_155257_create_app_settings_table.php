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
        Schema::create('app_settings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // Business details
            $t->string('business_name', 120)->nullable();
            $t->string('support_email', 190)->nullable();
            $t->string('support_phone', 40)->nullable();
            $t->string('business_address', 300)->nullable();
            // Booking time rules
            $t->unsignedSmallInteger('min_notice_hours')->default(24);
            $t->unsignedSmallInteger('max_days_ahead')->default(90);
            // Defaults
            $t->unsignedTinyInteger('quote_valid_days')->default(7);
            $t->unsignedSmallInteger('review_edit_hours')->default(168); // 7 days; 0 = reviews are final
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
