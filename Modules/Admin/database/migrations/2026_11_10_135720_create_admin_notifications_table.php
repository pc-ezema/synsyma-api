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
        Schema::create('admin_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 40)->index();
            $t->string('title', 160);
            $t->string('body', 500);
            $t->string('link', 255)->nullable();      // admin route, e.g. /bookings/{id}
            $t->string('permission', 60)->index();    // who may see it
            $t->uuid('booking_id')->nullable()->index();
            $t->timestamp('created_at')->useCurrent()->index();
        });
 
        // Read state is per admin.
        Schema::create('admin_notification_reads', function (Blueprint $t) {
            $t->uuid('admin_id');
            $t->uuid('notification_id');
            $t->timestamp('read_at')->useCurrent();
            $t->primary(['admin_id', 'notification_id']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_notifications');
    }
};
