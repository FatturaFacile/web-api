<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unified inbox (conversations and their messages), campaigns with their recipients and the per-channel contact
     * preferences. New tables only; consent is read from the CRM, never copied here.
     */
    public function up(): void
    {
        if (! Schema::hasTable('comm_conversations')) {
            Schema::create('comm_conversations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('channel', 20);
                $table->string('counterparty_address', 190);
                $table->string('counterparty_name', 160)->nullable();
                $table->uuid('contact_id')->nullable()->index();
                $table->string('subject', 255)->nullable();
                $table->string('status', 20)->default('open');
                $table->uuid('assigned_to')->nullable()->index();
                $table->timestamp('assigned_at')->nullable();
                $table->unsignedInteger('unread_count')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status', 'last_message_at']);
                $table->index(['tenant_id', 'channel', 'counterparty_address']);
            });
        }

        if (! Schema::hasTable('comm_conversation_messages')) {
            Schema::create('comm_conversation_messages', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('conversation_id')->constrained('comm_conversations')->cascadeOnDelete();
                $table->string('direction', 10);
                $table->string('channel', 20);
                $table->string('sender_address', 190)->nullable();
                $table->uuid('sender_user_id')->nullable();
                $table->text('body');
                $table->uuid('delivery_log_id')->nullable();
                $table->string('delivery_status', 20)->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['conversation_id', 'occurred_at']);
            });
        }

        if (! Schema::hasTable('comm_campaigns')) {
            Schema::create('comm_campaigns', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('channel', 20);
                $table->string('purpose', 20)->default('marketing');
                $table->uuid('segment_id')->nullable()->index();
                $table->string('subject', 255)->nullable();
                $table->text('body');
                $table->string('status', 20)->default('draft');
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->unsignedInteger('recipients_count')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('suppressed_count')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('comm_campaign_recipients')) {
            Schema::create('comm_campaign_recipients', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('campaign_id')->constrained('comm_campaigns')->cascadeOnDelete();
                $table->uuid('contact_id')->nullable()->index();
                $table->uuid('lead_id')->nullable()->index();
                $table->string('address', 190)->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('suppression_reason', 40)->nullable();
                $table->uuid('delivery_log_id')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['campaign_id', 'status']);
            });
        }

        if (! Schema::hasTable('comm_contact_preferences')) {
            Schema::create('comm_contact_preferences', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('contact_id')->index();
                $table->string('channel', 20);
                $table->boolean('is_opted_out')->default(false);
                $table->string('reason', 255)->nullable();
                $table->timestamp('changed_at')->nullable();
                $table->uuid('changed_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'contact_id', 'channel']);
            });
        }
    }

    public function down(): void
    {
        foreach (['comm_contact_preferences', 'comm_campaign_recipients', 'comm_campaigns', 'comm_conversation_messages', 'comm_conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
