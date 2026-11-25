<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 358 — corporate conventions, partner employee discounts and B2B welfare cards: partner conventions with their discount
 * matrix, SHA-256 employee lists, beneficiary enrolments with wallet passes, OTP challenges, split billing between the employee and
 * the employer, and welfare voucher redemptions (IT Art. 51 TUIR, CH Reka / Lunch-Check).
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL. Tenant isolation is the tenant_id column plus the TenantScope of the models.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->conventions();
        $this->discountRules();
        $this->allowedHashes();
        $this->enrollments();
        $this->otpChallenges();
        $this->splitBillings();
        $this->welfareRedemptions();
    }

    public function down(): void
    {
        foreach (['pos_welfare_voucher_redemptions', 'sales_convention_split_billings', 'sales_convention_otp_challenges', 'sales_convention_enrollments', 'sales_convention_allowed_hashes', 'sales_convention_discount_rules', 'sales_partner_conventions'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function conventions(): void
    {
        if (Schema::hasTable('sales_partner_conventions')) {
            return;
        }

        Schema::create('sales_partner_conventions', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->string('slug', 120)->unique();
            $table->string('name', 160);
            $table->string('partner_company_name', 160);
            $table->string('partner_vat_number', 20)->nullable();
            $table->string('partner_uid_number', 20)->nullable();
            $table->foreignUuid('partner_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('partner_type', 30);
            $table->string('country_code', 2);
            $table->string('currency', 3);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedInteger('eligible_population')->nullable();
            $table->string('hr_contact_name', 160)->nullable();
            $table->string('hr_contact_email', 190)->nullable();
            $table->string('hr_contact_phone', 40)->nullable();
            $table->string('contract_document_path', 255)->nullable();
            $table->json('verification_methods')->nullable();
            $table->json('allowed_email_domains')->nullable();
            $table->string('card_number_prefix', 40)->nullable();
            $table->string('card_number_regex', 190)->nullable();
            $table->string('static_convention_code', 60)->nullable();
            $table->boolean('allow_family_members')->default(false);
            $table->unsignedSmallInteger('max_family_members')->default(0);
            $table->decimal('annual_spending_cap_per_beneficiary', 15, 2)->nullable();
            $table->boolean('employer_subsidy_enabled')->default(false);
            $table->string('employer_subsidy_type', 20)->nullable();
            $table->decimal('employer_subsidy_value', 15, 4)->nullable();
            $table->decimal('employer_subsidy_annual_cap', 15, 2)->nullable();
            $table->string('subsidy_purpose', 30)->default('GENERAL');
            $table->boolean('split_billing_anonymized')->default(true);
            $table->string('billing_cycle', 20)->default('MONTHLY');
            $table->string('logo_path', 255)->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('welcome_message_key', 120)->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    private function discountRules(): void
    {
        if (Schema::hasTable('sales_convention_discount_rules')) {
            return;
        }

        Schema::create('sales_convention_discount_rules', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('convention_id')->constrained('sales_partner_conventions')->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('rule_type', 30);
            $table->foreignUuid('target_price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            $table->foreignUuid('target_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->decimal('discount_percentage', 7, 4)->default(0);
            $table->boolean('exclude_promotional_items')->default(true);
            $table->boolean('cumulative_with_other_discounts')->default(false);
            $table->decimal('min_cart_amount', 15, 2)->nullable();
            $this->audit($table);
            $table->index(['convention_id', 'priority']);
        });
    }

    private function allowedHashes(): void
    {
        if (Schema::hasTable('sales_convention_allowed_hashes')) {
            return;
        }

        Schema::create('sales_convention_allowed_hashes', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('convention_id')->constrained('sales_partner_conventions')->cascadeOnDelete();
            $table->char('identifier_sha256', 64)->index();
            $table->string('label_hint', 60)->nullable();
            $table->boolean('is_used')->default(false);
            $table->uuid('used_by_enrollment_id')->nullable();
            $this->audit($table);
            $table->unique(['convention_id', 'identifier_sha256']);
        });
    }

    private function enrollments(): void
    {
        if (Schema::hasTable('sales_convention_enrollments')) {
            return;
        }

        Schema::create('sales_convention_enrollments', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('convention_id')->constrained('sales_partner_conventions')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('beneficiary_type', 20);
            $table->uuid('parent_enrollment_id')->nullable()->index();
            $table->string('relationship', 10)->default('SELF');
            $table->string('full_name', 160);
            $table->string('verified_email', 190)->nullable();
            $table->string('membership_card_number', 60)->nullable();
            $table->char('identifier_hash', 64)->nullable()->index();
            $table->string('verification_method', 30);
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('status', 20)->default('PENDING_OTP');
            $table->uuid('wallet_pass_id')->unique();
            $table->string('qr_payload', 120);
            $table->unsignedSmallInteger('usage_year')->nullable();
            $table->decimal('annual_used_amount', 15, 2)->default(0);
            $table->decimal('annual_used_subsidy', 15, 2)->default(0);
            $this->audit($table);
            $table->index(['convention_id', 'status']);
            $table->index(['convention_id', 'verified_email']);
        });

        Schema::table('sales_convention_enrollments', function (Blueprint $table): void {
            $table->foreign('parent_enrollment_id')->references('id')->on('sales_convention_enrollments')->nullOnDelete();
        });

        Schema::table('sales_convention_allowed_hashes', function (Blueprint $table): void {
            $table->foreign('used_by_enrollment_id')->references('id')->on('sales_convention_enrollments')->nullOnDelete();
        });
    }

    private function otpChallenges(): void
    {
        if (Schema::hasTable('sales_convention_otp_challenges')) {
            return;
        }

        Schema::create('sales_convention_otp_challenges', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('convention_id')->constrained('sales_partner_conventions')->cascadeOnDelete();
            $table->string('email', 190);
            $table->string('otp_hash', 128);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('verified_at')->nullable();
            $this->audit($table);
            $table->index(['convention_id', 'email']);
        });
    }

    private function splitBillings(): void
    {
        if (Schema::hasTable('sales_convention_split_billings')) {
            return;
        }

        Schema::create('sales_convention_split_billings', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('convention_id')->constrained('sales_partner_conventions')->cascadeOnDelete();
            $table->foreignUuid('enrollment_id')->constrained('sales_convention_enrollments')->cascadeOnDelete();
            $table->string('source_document_type', 20);
            $table->uuid('source_document_id');
            $table->date('transaction_date');
            $table->string('currency', 3);
            $table->decimal('total_gross_amount', 15, 2);
            $table->decimal('convention_discount_amount', 15, 2)->default(0);
            $table->decimal('employer_subsidy_amount', 15, 2)->default(0);
            $table->decimal('employee_paid_amount', 15, 2)->default(0);
            $table->decimal('cost_amount', 15, 2)->default(0);
            $table->uuid('employee_document_id')->nullable();
            $table->foreignUuid('b2b_consolidated_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('billing_status', 30)->default('PENDING_CONSOLIDATION');
            $table->text('notes')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'source_document_type', 'source_document_id'], 'sales_conv_split_source_unique');
            $table->index(['convention_id', 'billing_status', 'transaction_date'], 'sales_conv_split_billing_idx');
        });
    }

    private function welfareRedemptions(): void
    {
        if (Schema::hasTable('pos_welfare_voucher_redemptions')) {
            return;
        }

        Schema::create('pos_welfare_voucher_redemptions', function (Blueprint $table): void {
            $this->base($table);
            $table->string('source_document_type', 20);
            $table->uuid('source_document_id');
            $table->foreignUuid('enrollment_id')->nullable()->constrained('sales_convention_enrollments')->nullOnDelete();
            $table->string('jurisdiction', 30);
            $table->string('provider', 30);
            $table->string('welfare_category', 30);
            $table->string('voucher_code_masked', 40);
            $table->char('voucher_barcode_hash', 64)->index();
            $table->string('currency', 3);
            $table->decimal('gross_voucher_amount', 15, 2);
            $table->decimal('merchant_fee_percentage', 7, 4)->default(0);
            $table->decimal('merchant_fee_amount', 15, 2)->default(0);
            $table->decimal('net_expected_reimbursement', 15, 2);
            $table->string('reimbursement_status', 30)->default('PENDING_SETTLEMENT');
            $table->string('settlement_batch_reference', 80)->nullable()->index();
            $table->string('provider_self_invoice_number', 80)->nullable();
            $table->decimal('settled_amount', 15, 2)->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'provider', 'voucher_barcode_hash'], 'pos_welfare_voucher_unique');
            $table->index(['tenant_id', 'reimbursement_status']);
        });
    }
};
