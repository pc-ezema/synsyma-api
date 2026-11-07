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
        Schema::create('promo_banners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 80);
            $table->string('subtitle', 160)->nullable();
            $table->string('image_path')->nullable();
            $table->string('link_type', 10)->default('none');     // none | service | category
            $table->string('link_value', 80)->nullable();         // service id (any option) or category slug
            $table->string('promo_code', 30)->nullable();         // shown on the banner; tapping copies it when there is no link
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_banners');
    }
};
