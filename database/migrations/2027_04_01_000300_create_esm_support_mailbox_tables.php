<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support mailboxes of a tenant, polled over IMAP to turn e-mail into tickets, and the log of every message read, which
 * also keeps a message from being imported twice. Credentials are stored encrypted by the model. New tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esm_support_mailboxes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('email_address', 180);
            $table->string('imap_host', 180);
            $table->unsignedInteger('imap_port')->default(993);
            $table->text('imap_username');
            $table->text('imap_password');
            $table->foreignUuid('default_team_id')->nullable()->constrained('esm_service_teams')->nullOnDelete();
            $table->foreignUuid('default_category_id')->nullable()->constrained('esm_service_categories')->nullOnDelete();
            $table->string('default_priority', 20)->default('medium');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'email_address']);
        });

        Schema::create('esm_support_mailbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('mailbox_id')->constrained('esm_support_mailboxes')->cascadeOnDelete();
            $table->unsignedBigInteger('uid');
            $table->string('message_id', 255)->nullable();
            $table->string('from_email', 180)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('outcome', 30);
            $table->foreignUuid('ticket_id')->nullable()->constrained('esm_tickets')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['mailbox_id', 'uid']);
            $table->index(['tenant_id', 'message_id']);
            $table->index(['tenant_id', 'from_email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_support_mailbox_messages');
        Schema::dropIfExists('esm_support_mailboxes');
    }
};
