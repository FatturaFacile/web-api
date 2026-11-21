<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interactive quotes and commercial proposals (Mod 036): the versioned CPQ quote (one row per revision, chained by
 * parent/root), its sections and lines, the signature envelopes, the tamper-evident audit events and the customer
 * comments. Tables are prefixed cpq_ because the simple back-office quote of the invoicing module keeps the quotes table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cpq_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('root_quote_id')->nullable()->index();
            $table->uuid('parent_quote_id')->nullable();
            $table->string('quote_number', 40);
            $table->string('number_prefix', 12)->default('OFF');
            $table->unsignedSmallInteger('number_year');
            $table->unsignedInteger('number_sequence');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->string('status', 24)->default('draft');
            $table->string('jurisdiction', 2)->default('IT');
            $table->string('locale', 8)->default('it-IT');
            $table->uuid('contact_id')->nullable()->index();
            $table->uuid('sales_rep_id')->nullable()->index();
            $table->string('customer_name', 190)->nullable();
            $table->string('customer_email', 190)->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->date('valid_until')->nullable();
            $table->string('payment_terms', 120)->nullable();
            $table->string('incoterms', 12)->nullable();
            $table->text('cover_letter')->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->decimal('header_discount_percent', 7, 4)->default(0);
            $table->decimal('min_margin_percent', 7, 4)->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->boolean('auto_convert')->default(false);
            $table->string('auto_convert_target', 16)->nullable();
            $table->string('signature_level', 16)->default('ses_otp');
            $table->decimal('total_net', 18, 2)->default(0);
            $table->decimal('total_tax', 18, 2)->default(0);
            $table->decimal('total_gross', 18, 2)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->decimal('margin_percent', 9, 4)->nullable();
            $table->jsonb('frozen_snapshot')->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->string('public_token_hash', 64)->nullable()->unique();
            $table->string('public_pin_hash', 255)->nullable();
            $table->timestamp('public_expires_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->jsonb('customer_selections')->nullable();
            $table->string('signed_document_path', 255)->nullable();
            $table->string('signed_document_hash', 64)->nullable();
            $table->string('audit_certificate_path', 255)->nullable();
            $table->string('audit_certificate_hash', 64)->nullable();
            $table->uuid('converted_sales_order_id')->nullable()->unique();
            $table->uuid('converted_invoice_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'quote_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'valid_until']);
        });

        Schema::create('cpq_quote_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('cpq_quotes')->cascadeOnDelete();
            $table->uuid('origin_id')->nullable();
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->boolean('page_break_before')->default(false);
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'quote_id', 'sort_order']);
        });

        Schema::create('cpq_quote_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('cpq_quotes')->cascadeOnDelete();
            $table->uuid('section_id')->nullable()->index();
            $table->uuid('origin_id')->nullable();
            $table->string('line_type', 32);
            $table->string('selection_mode', 24)->default('mandatory');
            $table->string('alternative_group', 64)->nullable();
            $table->boolean('is_default_selected')->default(true);
            $table->uuid('product_id')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('description', 500);
            $table->text('long_description')->nullable();
            $table->string('uom', 16)->nullable();
            $table->string('resource_role', 120)->nullable();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->decimal('discount_fixed', 18, 4)->default(0);
            $table->jsonb('tier_prices')->nullable();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'quote_id', 'sort_order']);
        });

        Schema::create('cpq_signature_envelopes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('cpq_quotes')->cascadeOnDelete();
            $table->string('level', 16);
            $table->string('provider', 32);
            $table->string('status', 16)->default('pending');
            $table->string('signer_name', 190);
            $table->string('signer_role', 120)->nullable();
            $table->string('signer_email', 190)->nullable();
            $table->string('signer_phone', 40)->nullable();
            $table->string('otp_channel', 8)->nullable();
            $table->string('otp_hash', 255)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->string('provider_reference', 190)->nullable()->index();
            $table->string('document_hash', 64)->nullable();
            $table->boolean('consent_given')->default(false);
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_user_agent', 500)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->jsonb('verification_metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'quote_id', 'status']);
        });

        Schema::create('cpq_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('cpq_quotes')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('event', 40);
            $table->string('actor', 190)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('event_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['quote_id', 'sequence']);
            $table->index(['tenant_id', 'event']);
        });

        Schema::create('cpq_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('cpq_quotes')->cascadeOnDelete();
            $table->uuid('line_id')->nullable();
            $table->string('author_type', 12)->default('customer');
            $table->string('author_name', 190)->nullable();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'quote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpq_comments');
        Schema::dropIfExists('cpq_audit_events');
        Schema::dropIfExists('cpq_signature_envelopes');
        Schema::dropIfExists('cpq_quote_lines');
        Schema::dropIfExists('cpq_quote_sections');
        Schema::dropIfExists('cpq_quotes');
    }
};
