<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sdi_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('transmitter_country_code', 2)->default('IT');
            $table->string('transmitter_fiscal_code', 28)->nullable();
            $table->unsignedBigInteger('progressivo_invio_counter')->default(0);
            $table->string('channel_type', 30)->nullable(); // pec, webservice_sdi, intermediary_api
            $table->text('encrypted_credentials')->nullable();
            $table->string('default_codice_destinatario', 7)->default('0000000');
            $table->string('pec_address')->nullable();
            $table->string('signature_mode', 30)->default('none'); // none, local_pkcs12, remote_hsm_provider
            $table->text('encrypted_certificate')->nullable();
            $table->string('certificate_reference')->nullable();
            $table->boolean('auto_sign_on_send')->default(false);
            $table->boolean('legal_archiving_enabled')->default(false);
            $table->boolean('sandbox_simulator_enabled')->default(false);
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $this->auditUsers($table);

            $table->unique('tenant_id');
        });

        Schema::create('fpa_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('passive_invoice_id')->nullable()->constrained('passive_invoices')->nullOnDelete();
            $table->foreignUuid('parent_fpa_document_id')->nullable()->constrained('fpa_documents')->nullOnDelete();
            $table->string('direction', 10); // outbound, inbound
            $table->string('format_transmission', 5); // FPR12, FPA12, FSM10
            $table->string('document_type', 4); // TD01..TD29
            $table->string('document_number', 20)->nullable();
            $table->date('document_date')->nullable();
            $table->string('sdi_identifier', 64)->nullable();
            $table->string('file_name')->nullable();
            $table->string('transmission_progressive', 10)->nullable();
            $table->string('recipient_code', 7)->nullable();
            $table->string('recipient_pec')->nullable();
            $table->string('status', 30)->default('draft');
            $table->string('xml_storage_path')->nullable();
            $table->string('signed_storage_path')->nullable();
            $table->string('sha256_hash', 64)->nullable();
            $table->json('validation_errors')->nullable();
            $table->timestamp('transmitted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('resubmission_deadline_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $this->auditUsers($table);

            $table->unique(['tenant_id', 'sdi_identifier']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'direction']);
            $table->index('invoice_id');
            $table->index(['tenant_id', 'document_number', 'document_date']);
        });

        Schema::create('sdi_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('fpa_document_id')->constrained('fpa_documents')->cascadeOnDelete();
            $table->string('notification_type', 4); // NS, RC, MC, NE, DT, AT, MT
            $table->string('sdi_identifier', 64)->nullable();
            $table->string('sdi_message_id', 64)->nullable();
            $table->string('outcome_code', 10)->nullable(); // EC01, EC02
            $table->json('error_codes')->nullable();
            $table->string('raw_xml_path')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $this->auditUsers($table);

            $table->index(['tenant_id', 'fpa_document_id']);
            $table->index(['fpa_document_id', 'notification_type']);
        });
    }

    private function auditUsers(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
    }

    public function down(): void
    {
        Schema::dropIfExists('sdi_notifications');
        Schema::dropIfExists('fpa_documents');
        Schema::dropIfExists('sdi_configurations');
    }
};
