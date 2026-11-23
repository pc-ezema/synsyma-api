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
        Schema::create('app_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('platform', 10)->unique();           // ios | android
            $t->string('min_version', 20)->default('1.0.0');    // below this: forced update
            $t->string('latest_version', 20)->default('1.0.0'); // below this: soft "update available"
            $t->string('store_url', 300)->nullable();
            $t->string('message', 300)->nullable();
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
