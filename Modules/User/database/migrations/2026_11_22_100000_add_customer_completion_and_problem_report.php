<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            $t->string('completed_by', 12)->nullable();            // customer | admin | system
            $t->timestamp('problem_reported_at')->nullable();      // set while a customer problem is open
            $t->string('problem_note', 1000)->nullable();
        });
        Schema::table('booking_policies', function (Blueprint $t) {
            // Hours after the job should have ended before it completes by itself. 0 turns auto-complete off.
            $t->unsignedSmallInteger('auto_complete_hours')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['completed_by', 'problem_reported_at', 'problem_note']));
        Schema::table('booking_policies', fn (Blueprint $t) => $t->dropColumn('auto_complete_hours'));
    }
};