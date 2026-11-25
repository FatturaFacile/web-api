<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Electronic signatures (FES / FEA / FEQ, eIDAS and ZertES): provider configurations, workflow templates, signature
 * envelopes with their recipients and the append-only, hash-chained audit trail.
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->providerConfigs();
        $this->templates();
        $this->envelopes();
        $this->recipients();
        $this->auditLogs();
    }

    public function down(): void
    {
        foreach (['signature_audit_logs', 'signature_recipients', 'signature_envelopes', 'signature_templates', 'signature_provider_configs'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function providerConfigs(): void
    {
        if (Schema::hasTable('signature_provider_configs')) {
            return;
        }

        Schema::create('signature_provider_configs', function (Blueprint $table): void {
            $this->base($table);
            $table->string('provider', 20); // native | swisscom | infocert | namirial
            $table->string('jurisdiction', 10)->default('BOTH'); // CH | EU | BOTH
            $table->string('name', 160);
            $table->text('credentials')->nullable(); // encrypted array: keys, secrets, client certificate, webhook secret
            $table->string('certificate_path', 500)->nullable(); // PKCS#12 of the native seal
            $table->boolean('is_sandbox')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable(); // endpoint overrides, key identity, language
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->string('last_test_message', 500)->nullable();
            $this->audit($table);
            $table->index(['tenant_id', 'provider', 'is_active'], 'sig_provider_configs_tenant_idx');
        });
    }

    private function templates(): void
    {
        if (Schema::hasTable('signature_templates')) {
            return;
        }

        Schema::create('signature_templates', function (Blueprint $table): void {
            $this->base($table);
            $table->string('name', 160);
            $table->string('document_type', 30); // quote | purchase_order | employment_contract | document | upload
            $table->string('required_level', 3); // FES | FEA | FEQ
            $table->string('jurisdiction', 10)->default('eIDAS');
            $table->foreignUuid('provider_config_id')->nullable()->constrained('signature_provider_configs')->nullOnDelete();
            $table->string('routing_mode', 12)->default('sequential');
            $table->unsignedSmallInteger('expiration_days')->default(14);
            $table->json('reminder_days')->nullable(); // days before the expiry, e.g. [3, 1]
            $table->decimal('min_amount', 15, 2)->nullable(); // auto rule: applies from this document amount
            $table->string('amount_currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->index(['tenant_id', 'document_type', 'is_active'], 'sig_templates_tenant_type_idx');
        });
    }

    private function envelopes(): void
    {
        if (Schema::hasTable('signature_envelopes')) {
            return;
        }

        Schema::create('signature_envelopes', function (Blueprint $table): void {
            $this->base($table);
            $table->string('signable_type', 40)->nullable(); // alias of SignableType
            $table->uuid('signable_id')->nullable();
            $table->string('verification_code', 40)->unique();
            $table->string('title', 200);
            $table->string('subject', 200)->nullable();
            $table->text('message')->nullable();
            $table->string('required_level', 3);
            $table->string('jurisdiction', 10);
            $table->string('routing_mode', 12);
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('provider_config_id')->nullable()->constrained('signature_provider_configs')->nullOnDelete();
            $table->string('provider', 20);
            $table->string('locale', 5)->default('it');
            $table->string('document_format', 10)->default('pades'); // pades (PDF) | cades (.p7m) | xades (XML)
            $table->string('file_name', 255)->nullable();
            $table->string('original_path', 500)->nullable();
            $table->string('original_sha256', 64)->nullable();
            $table->string('working_path', 500)->nullable(); // the PDF the hash signatures are applied to, one after the other
            $table->string('signed_path', 500)->nullable();
            $table->string('signed_sha256', 64)->nullable();
            $table->string('external_id', 190)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_reminder_at')->nullable();
            $table->json('reminders_sent')->nullable();
            $table->json('metadata')->nullable();
            $this->audit($table);
            $table->index(['tenant_id', 'status'], 'sig_envelopes_tenant_status_idx');
            $table->index(['tenant_id', 'signable_type', 'signable_id'], 'sig_envelopes_signable_idx');
            $table->index(['status', 'expires_at'], 'sig_envelopes_expiry_idx');
            $table->index('original_sha256', 'sig_envelopes_original_hash_idx');
            $table->index('signed_sha256', 'sig_envelopes_signed_hash_idx');
            $table->index('external_id', 'sig_envelopes_external_idx');
        });
    }

    private function recipients(): void
    {
        if (Schema::hasTable('signature_recipients')) {
            return;
        }

        Schema::create('signature_recipients', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('envelope_id')->constrained('signature_envelopes')->cascadeOnDelete();
            $table->unsignedSmallInteger('signing_order')->default(1);
            $table->string('role', 20)->default('SIGNER');
            $table->string('full_name', 160);
            $table->string('email', 190);
            $table->string('phone', 20)->nullable(); // E.164
            $table->text('identity_metadata')->nullable(); // encrypted array: fiscal code, AHV number, ID document
            $table->string('auth_method', 20)->default('email_link');
            $table->string('access_token_hash', 64)->nullable();
            $table->text('access_token')->nullable(); // encrypted: reminders re-send the same personal link
            $table->timestamp('token_expires_at')->nullable();
            $table->string('otp_hash', 64)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->json('signature_box')->nullable(); // {page, x, y, width, height}
            $table->string('status', 20)->default('waiting');
            $table->string('external_id', 190)->nullable();
            $table->string('prepared_path', 500)->nullable();
            $table->longText('signature_data')->nullable(); // typed name or drawn PNG (data URI)
            $table->string('signature_method', 30)->nullable();
            $table->string('certificate_serial', 120)->nullable();
            $table->string('certificate_subject', 300)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $this->audit($table);
            $table->unique('access_token_hash', 'sig_recipients_token_uq');
            $table->index(['tenant_id', 'envelope_id', 'signing_order'], 'sig_recipients_envelope_idx');
            $table->index('external_id', 'sig_recipients_external_idx');
        });
    }

    private function auditLogs(): void
    {
        if (Schema::hasTable('signature_audit_logs')) {
            return;
        }

        // Append-only: no soft delete, no updated_at. The model refuses updates and deletes; the chain detects tampering.
        Schema::create('signature_audit_logs', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('envelope_id')->constrained('signature_envelopes')->cascadeOnDelete();
            $table->uuid('recipient_id')->nullable();
            $table->unsignedInteger('sequence');
            $table->string('event', 40);
            $table->string('actor_type', 20); // user | recipient | system | provider
            $table->string('actor_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->string('document_sha256', 64)->nullable();
            $table->text('receipt')->nullable(); // CA transaction id / receipt
            $table->text('timestamp_token')->nullable(); // RFC 3161 token (base64)
            $table->string('previous_hash', 64);
            $table->string('entry_hash', 64);
            $table->timestamp('occurred_at', 6);
            $table->timestamp('created_at')->nullable();
            $table->unique(['envelope_id', 'sequence'], 'sig_audit_sequence_uq');
            $table->index(['tenant_id', 'envelope_id'], 'sig_audit_envelope_idx');
        });
    }
};
