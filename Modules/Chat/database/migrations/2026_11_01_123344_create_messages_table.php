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
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('sender_role', 10);               // user | admin
            $table->uuid('sender_id');                       // users.id or admins.id
            $table->uuid('client_id')->nullable();           // lets the app retry a send safely
            $table->string('type', 10)->default('text');     // text | image
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamp('read_at', 6)->nullable();
            $table->timestamps(6);                           // microseconds, so ordering is exact
            $table->softDeletes();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'sender_role', 'read_at']);
            $table->unique(['conversation_id', 'client_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
