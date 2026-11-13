<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces your existing contractors migration (same file, same name).
 * Only the "verification" block inside `contractors` is new; the other tables are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 12);                       // company | individual
            $t->string('name', 160);                      // business name, or the person's name
            $t->string('contact_name', 120);
            $t->string('email', 190);
            $t->string('phone', 30);
            $t->string('country', 2);                     // CA | US | GB
            $t->string('address_line1', 160);
            $t->string('city', 80);
            $t->string('region', 80);
            $t->string('postal_code', 12);
            $t->string('tax_number', 60)->nullable();
            $t->text('about')->nullable();                // what they say about themselves
            $t->string('status', 12)->default('pending'); // pending | approved | suspended | rejected
            $t->string('status_reason', 300)->nullable();
            $t->decimal('commission_percent', 5, 2)->nullable(); // null = use the platform default
            $t->text('admin_notes')->nullable();

            // ---- verification ----
            $t->string('id_type', 20)->nullable();        // passport | drivers_licence | national_id (the file is a contractor_document of kind "id")
            $t->json('social_links')->nullable();         // {instagram, facebook, linkedin, x, website}. Admin only.
            $t->timestamp('tax_ack_at')->nullable();      // agreed they are responsible for their own taxes
            $t->timestamp('background_consent_at')->nullable(); // agreed to a background check
            $t->string('consent_version', 20)->nullable();      // which wording they agreed to
            $t->string('consent_source', 8)->nullable();        // self | admin (recorded on their behalf)
            $t->string('consent_recorded_by', 36)->nullable();  // admin id when consent_source = admin
            $t->string('consent_ip', 45)->nullable();
            $t->string('background_status', 12)->default('not_started'); // not_started | in_progress | passed | failed
            $t->timestamp('background_checked_at')->nullable();
            $t->text('background_notes')->nullable();

            $t->foreignUuid('campaign_id')->nullable()->constrained('contractor_campaigns')->nullOnDelete();
            $t->string('source', 12)->default('self');    // self (applied) | admin (added by an admin)
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['status', 'country']);
            $t->index('type');
        });

        Schema::create('contractor_categories', function (Blueprint $t) {
            $t->foreignUuid('contractor_id')->constrained('contractors')->cascadeOnDelete();
            $t->foreignUuid('category_id')->constrained('service_categories')->cascadeOnDelete();
            $t->primary(['contractor_id', 'category_id']);
        });

        Schema::create('contractor_areas', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('contractor_id')->constrained('contractors')->cascadeOnDelete();
            $t->string('country', 2);
            $t->string('region', 80);
            $t->string('city', 80)->nullable();           // empty = the whole region
            $t->timestamps();
            $t->index(['country', 'region', 'city']);
        });

        Schema::create('contractor_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('contractor_id')->constrained('contractors')->cascadeOnDelete();
            $t->string('kind', 16);                       // id | insurance | licence | other
            $t->string('path');                           // private disk, never public
            $t->string('original_name', 190);
            $t->string('mime', 60);
            $t->unsignedInteger('size');
            $t->boolean('verified')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_documents');
        Schema::dropIfExists('contractor_areas');
        Schema::dropIfExists('contractor_categories');
        Schema::dropIfExists('contractors');
    }
};