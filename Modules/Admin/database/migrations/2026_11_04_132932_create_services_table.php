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
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('service_categories')->restrictOnDelete();

            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->string('tier', 10)->nullable();            // premium | regular | null (no badge)
            $table->string('description', 1000)->nullable();
            $table->string('image_path', 255)->nullable();

            // hourly: base_price covers the first included_hours, then additional_hour_price per extra hour
            // fixed : one flat base_price
            // quote : no price, the customer asks for a quote
            $table->string('price_type', 10);
            $table->char('currency', 3)->default('USD');
            $table->decimal('base_price', 10, 2)->nullable();
            $table->unsignedTinyInteger('included_hours')->nullable();
            $table->decimal('additional_hour_price', 10, 2)->nullable();

            $table->unsignedTinyInteger('team_size')->nullable();   // 1 = one professional, 2 = a team of two
            $table->boolean('parts_tbd')->default(false);           // "fixes with parts replacement: TBD"
            $table->boolean('home_protection')->default(true);      // "Home protection plan inclusive"

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
