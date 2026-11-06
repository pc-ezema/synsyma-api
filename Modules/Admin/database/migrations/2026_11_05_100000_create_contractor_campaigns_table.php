<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per advert / QR code, so you can see which advert brings contractors in.
        Schema::create('contractor_campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 120);
            $t->string('code', 24)->unique();
            $t->unsignedInteger('scans')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_campaigns');
    }
};
