<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
            $table->string('deactivation_reason', 300)->nullable()->after('deactivated_at');
            $table->uuid('deactivated_by')->nullable()->after('deactivation_reason'); // the admin who did it
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deactivated_at', 'deactivation_reason', 'deactivated_by']);
        });
    }
};