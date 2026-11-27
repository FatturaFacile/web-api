<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #283 (Mod 025) — Swiss eBill direct transmission through SIX Paynet / PostFinance: per-company settings, the
 * transmissions with their immutable event trail, the webhook deduplication table and the participant directory cache,
 * plus the eBill columns of the customers and of the invoice summary. Dialect-agnostic (SQLite and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->settings();
        $this->transmissions();
        $this->events();
        $this->webhookEvents();
        $this->participantLookups();
        $this->contactColumns();
        $this->invoiceColumns();
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            foreach (['ebill_status', 'ebill_transmission_id', 'ebill_last_event_at'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('contacts', function (Blueprint $table): void {
            foreach (['ebill_recipient_id', 'ebill_status', 'ebill_channel_type', 'ebill_verified_at', 'ebill_opt_in_preferred'] as $column) {
                if (Schema::hasColumn('contacts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        foreach (['ebill_participant_lookups', 'ebill_webhook_events', 'ebill_transmission_events', 'ebill_transmissions', 'ebill_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function settings(): void
    {
        if (Schema::hasTable('ebill_settings')) {
            return;
        }

        Schema::create('ebill_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->unique()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('ebill_enabled')->default(false);
            $table->string('biller_id', 17)->nullable();
            $table->string('provider', 30)->default('sandbox_simulator');
            $table->string('channel_protocol', 30)->default('rest_json_xml');
            $table->string('api_base_url', 255)->nullable();
            $table->string('client_id', 190)->nullable();
            $table->text('client_secret')->nullable(); // encrypted cast
            $table->text('signing_certificate_pem')->nullable(); // encrypted cast
            $table->text('private_key_pem')->nullable(); // encrypted cast
            $table->text('webhook_secret')->nullable(); // encrypted cast
            $table->string('webhook_slug', 64)->unique();
            $table->string('webhook_auth_mode', 20)->default('hmac_sha256'); // hmac_sha256, jws_hs256, mutual_tls
            $table->string('webhook_mtls_fingerprint', 128)->nullable();
            $table->boolean('auto_send_on_invoice_issue')->default(false);
            $table->boolean('attach_courtesy_pdf')->default(true);
            $table->string('default_ebinterface_version', 40)->default('SIX eBill XML');
            $table->timestamp('last_connection_tested_at')->nullable();
            $table->string('connection_status', 20)->default('untested');
            $table->unsignedInteger('last_connection_latency_ms')->nullable();
            $table->string('last_connection_message', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function transmissions(): void
    {
        if (Schema::hasTable('ebill_transmissions')) {
            return;
        }

        Schema::create('ebill_transmissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->uuid('supersedes_id')->nullable(); // the rejected transmission this one revises
            $table->string('status', 30)->default('draft');
            $table->string('channel_type', 20)->nullable();
            $table->string('idempotency_key', 80)->unique();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('biller_id', 17);
            $table->string('recipient_id', 64);
            $table->string('six_transaction_id', 80)->nullable()->index();
            $table->timestamp('submission_timestamp')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('scheduled_payment_date')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_code', 40)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_details')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('camt054_entry_id', 120)->nullable();
            $table->date('settlement_value_date')->nullable();
            $table->decimal('settled_amount', 15, 2)->default(0);
            $table->decimal('amount_discrepancy', 15, 2)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('invoice_amount', 15, 2);
            $table->string('currency', 3);
            $table->string('ebinterface_version', 40)->nullable();
            $table->longText('xml_payload')->nullable();
            $table->string('xml_sha256', 64)->nullable();
            $table->string('pdf_sha256', 64)->nullable();
            $table->unsignedInteger('pdf_size_bytes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['status', 'next_retry_at']);
        });
    }

    private function events(): void
    {
        if (Schema::hasTable('ebill_transmission_events')) {
            return;
        }

        // Append-only: no updated_at, no soft deletes.
        Schema::create('ebill_transmission_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('transmission_id')->constrained('ebill_transmissions')->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('source', 20);
            $table->string('gateway_event_id', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->jsonb('details')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['transmission_id', 'occurred_at']);
        });
    }

    private function webhookEvents(): void
    {
        if (Schema::hasTable('ebill_webhook_events')) {
            return;
        }

        Schema::create('ebill_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('webhook_event_id', 120);
            $table->string('event_type', 40)->nullable();
            $table->uuid('transmission_id')->nullable();
            $table->string('payload_hash', 64);
            $table->jsonb('payload')->nullable();
            $table->string('outcome', 40)->default('received');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'webhook_event_id']);
        });
    }

    private function participantLookups(): void
    {
        if (Schema::hasTable('ebill_participant_lookups')) {
            return;
        }

        Schema::create('ebill_participant_lookups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('lookup_type', 20);
            $table->string('lookup_key_hash', 64);
            $table->boolean('found')->default(false);
            $table->jsonb('result')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'lookup_type', 'lookup_key_hash'], 'ebill_lookup_unique');
        });
    }

    private function contactColumns(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            if (! Schema::hasColumn('contacts', 'ebill_recipient_id')) {
                $table->string('ebill_recipient_id', 64)->nullable()->index();
            }

            if (! Schema::hasColumn('contacts', 'ebill_status')) {
                $table->string('ebill_status', 20)->default('unknown');
            }

            if (! Schema::hasColumn('contacts', 'ebill_channel_type')) {
                $table->string('ebill_channel_type', 20)->nullable();
            }

            if (! Schema::hasColumn('contacts', 'ebill_verified_at')) {
                $table->timestamp('ebill_verified_at')->nullable();
            }

            if (! Schema::hasColumn('contacts', 'ebill_opt_in_preferred')) {
                $table->boolean('ebill_opt_in_preferred')->default(true);
            }
        });
    }

    private function invoiceColumns(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('invoices', 'ebill_status')) {
                $table->string('ebill_status', 30)->nullable()->index();
            }

            if (! Schema::hasColumn('invoices', 'ebill_transmission_id')) {
                $table->uuid('ebill_transmission_id')->nullable();
            }

            if (! Schema::hasColumn('invoices', 'ebill_last_event_at')) {
                $table->timestamp('ebill_last_event_at')->nullable();
            }
        });
    }
};
