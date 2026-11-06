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
        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('label', 40)->nullable();          // Home, Office...
            $table->char('country', 2);                        // CA | US | GB
            $table->string('address_line1', 160);
            $table->string('address_line2', 160)->nullable();
            $table->string('city', 80);
            $table->string('region', 80);                      // province / state / nation
            $table->string('postal_code', 12);
            $table->string('instructions', 300)->nullable();   // "Gate code 1234, ring twice"
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
