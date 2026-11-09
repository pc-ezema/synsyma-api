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
        Schema::create('reviews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('booking_id')->unique(); // one review per booking
            $t->uuid('user_id');
            $t->uuid('contractor_id')->nullable()->index();
            $t->unsignedTinyInteger('rating');                 // 1 to 5
            $t->text('comment')->nullable();
            $t->boolean('is_hidden')->default(false)->index(); // hidden by an admin: kept, but not counted
            $t->string('hidden_reason', 300)->nullable();
            $t->uuid('hidden_by')->nullable();
            $t->timestamp('edited_at')->nullable();
            $t->timestamps();
 
            $t->index(['contractor_id', 'is_hidden']);
            $t->index('rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
