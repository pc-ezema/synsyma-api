<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Mark all as read": everything up to this moment counts as read for that admin.
        if (! Schema::hasTable('admin_notification_cursors')) {
            Schema::create('admin_notification_cursors', function (Blueprint $t) {
                $t->uuid('admin_id')->primary();
                $t->timestamp('read_all_at');
            });
        }

        // Per admin, per kind of alert: browser push and email. No row means the default.
        if (! Schema::hasTable('admin_notification_prefs')) {
            Schema::create('admin_notification_prefs', function (Blueprint $t) {
                $t->uuid('admin_id');
                $t->string('type', 40);
                $t->boolean('push')->default(true);
                $t->boolean('email')->default(false);
                $t->timestamps();
                $t->primary(['admin_id', 'type']);
            });
        }

        // The browsers an admin has turned alerts on in.
        if (! Schema::hasTable('admin_push_tokens')) {
            Schema::create('admin_push_tokens', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('admin_id')->index();
                $t->string('token', 400)->unique();
                $t->string('user_agent', 255)->nullable();
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_push_tokens');
        Schema::dropIfExists('admin_notification_prefs');
        Schema::dropIfExists('admin_notification_cursors');
    }
};