<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversation of a helpdesk ticket: public replies and internal notes, their attachments on the private disk, and
 * the read state of each colleague. New tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esm_ticket_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained('esm_tickets')->cascadeOnDelete();
            $table->string('author_type', 20);
            $table->foreignUuid('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('author_contact_id')->nullable();
            $table->string('author_name', 180)->nullable();
            $table->string('author_email', 180)->nullable();
            $table->string('visibility', 20)->default('public');
            $table->string('channel', 20)->default('web');
            $table->text('body');
            $table->string('email_message_id', 255)->nullable();
            $table->string('in_reply_to', 255)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'ticket_id', 'created_at']);
            $table->index(['tenant_id', 'email_message_id']);
        });

        Schema::create('esm_ticket_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained('esm_tickets')->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained('esm_ticket_messages')->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150)->default('application/octet-stream');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'message_id']);
        });

        Schema::create('esm_ticket_reads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained('esm_tickets')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_read_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id']);
            $table->unique(['ticket_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_ticket_reads');
        Schema::dropIfExists('esm_ticket_attachments');
        Schema::dropIfExists('esm_ticket_messages');
    }
};
