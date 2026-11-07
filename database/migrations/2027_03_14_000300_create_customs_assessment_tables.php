<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The decisions are append-only, so they keep no foreign key towards tables a restore empties, and everything that
     * changes after the download lives elsewhere: the ledger state in `customs_ledger_postings`, the retrieval outcome
     * in `customs_retrieval_runs`. A corrected decision is a new row with a higher version.
     */
    public function up(): void
    {
        Schema::create('customs_assessment_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('declaration_id')->nullable();
            $table->string('mrn', 30);
            $table->unsignedSmallInteger('version');
            $table->string('document_type', 40);
            $table->string('direction', 10);
            $table->date('document_date');
            $table->string('trader_declaration_number', 22)->nullable();
            $table->decimal('duty_amount', 16, 4)->default(0);
            $table->decimal('vat_amount', 16, 4)->default(0);
            $table->decimal('vat_base_amount', 16, 4)->default(0);
            $table->decimal('vat_rate', 7, 2)->nullable();
            $table->boolean('amount_not_charged')->default(false);
            $table->string('xml_path', 500);
            $table->char('xml_sha256', 64);
            $table->string('pdf_path', 500)->nullable();
            $table->char('pdf_sha256', 64)->nullable();
            $table->string('envelope_path', 500)->nullable();
            $table->char('envelope_sha256', 64)->nullable();
            $table->string('signature_status', 20);
            $table->jsonb('signature_report')->nullable();
            $table->string('gateway', 20);
            $table->timestamp('retrieved_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'mrn', 'version', 'document_type'], 'customs_assessment_identity_unique');
            $table->index(['tenant_id', 'document_date']);
            $table->index(['tenant_id', 'direction', 'document_type']);
            $table->index(['tenant_id', 'declaration_id']);
        });

        Schema::create('customs_retrieval_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('gateway', 20);
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status', 20);
            $table->unsignedInteger('listed_count')->default(0);
            $table->unsignedInteger('stored_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->jsonb('errors')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at']);
        });

        Schema::create('customs_ledger_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('assessment_decision_id')->unique();
            $table->string('status', 20);
            $table->uuid('journal_entry_id')->nullable();
            $table->date('posting_date');
            $table->decimal('duty_amount', 16, 2)->default(0);
            $table->decimal('vat_amount', 16, 2)->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->foreign('assessment_decision_id')->references('id')->on('customs_assessment_decisions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_ledger_postings');
        Schema::dropIfExists('customs_retrieval_runs');
        Schema::dropIfExists('customs_assessment_decisions');
    }
};
