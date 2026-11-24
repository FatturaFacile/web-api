<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Donors, donations with their numbered receipts, campaign expenses, the 5x1000 allocations with their classified
     * expenses, and sponsors with their contracts. Receipts are append-only: a wrong one is cancelled, never changed.
     */
    public function up(): void
    {
        Schema::table('assoc_settings', function (Blueprint $table): void {
            $table->string('donation_receipt_prefix', 20)->default('DON-');
            $table->uuid('donation_revenue_account_id')->nullable();
            $table->uuid('five_per_mille_revenue_account_id')->nullable();
        });

        Schema::create('assoc_donors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20)->default('person');
            $table->uuid('member_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->text('name');
            $table->text('tax_code')->nullable();
            $table->string('tax_code_hash', 64)->nullable();
            $table->text('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'tax_code_hash']);
            $table->index(['tenant_id', 'member_id']);
        });

        Schema::create('assoc_donations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('donor_id')->nullable();
            $table->uuid('campaign_id')->nullable();
            $table->string('kind', 20)->default('money');
            $table->decimal('amount', 14, 2);
            $table->text('in_kind_description')->nullable();
            $table->date('received_on');
            $table->string('payment_method', 20)->nullable();
            $table->string('purpose', 500)->nullable();
            $table->uuid('revenue_account_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'received_on']);
            $table->index(['tenant_id', 'campaign_id']);
            $table->index(['tenant_id', 'donor_id']);
        });

        Schema::create('assoc_donation_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('donation_id');
            $table->string('number', 40);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('sequential_number');
            $table->date('issued_on');
            $table->decimal('amount', 14, 2);
            $table->text('holder');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'donation_id']);
        });

        Schema::create('assoc_campaign_expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('assoc_campaigns')->cascadeOnDelete();
            $table->date('incurred_on');
            $table->string('description', 500);
            $table->decimal('amount', 14, 2);
            $table->uuid('expense_account_id')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'campaign_id']);
        });

        Schema::create('assoc_expense_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('scope', 30);
            $table->string('code', 50);
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'scope']);
        });

        Schema::create('assoc_five_per_mille_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reference_year', 20);
            $table->date('received_on');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 20)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'received_on']);
        });

        Schema::create('assoc_five_per_mille_expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('allocation_id')->constrained('assoc_five_per_mille_allocations')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('assoc_expense_categories');
            $table->date('incurred_on');
            $table->string('description', 500);
            $table->decimal('amount', 14, 2);
            $table->uuid('journal_entry_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'allocation_id']);
        });

        Schema::create('assoc_sponsors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('contact_id')->nullable();
            $table->string('name');
            $table->string('vat_number', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('assoc_sponsor_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('sponsor_id')->constrained('assoc_sponsors')->cascadeOnDelete();
            $table->string('title');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->decimal('amount', 14, 2);
            $table->text('consideration')->nullable();
            $table->string('status', 20)->default('active');
            $table->uuid('campaign_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('original_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'sponsor_id']);
            $table->index(['tenant_id', 'ends_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assoc_donations ADD CONSTRAINT assoc_donations_amount_check CHECK (amount > 0)');
            DB::statement('CREATE UNIQUE INDEX assoc_donation_receipts_active_unique ON assoc_donation_receipts (donation_id) WHERE cancelled_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_expense_categories_unique ON assoc_expense_categories (COALESCE(tenant_id, \'00000000-0000-0000-0000-000000000000\'), scope, code) WHERE deleted_at IS NULL');

            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_assoc_donation_receipt_mutation()
                RETURNS TRIGGER AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Donation receipts cannot be deleted.';
                    END IF;
                    IF OLD.cancelled_at IS NOT NULL
                        OR NEW.number IS DISTINCT FROM OLD.number OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.issued_on IS DISTINCT FROM OLD.issued_on OR NEW.holder IS DISTINCT FROM OLD.holder
                        OR NEW.donation_id IS DISTINCT FROM OLD.donation_id THEN
                        RAISE EXCEPTION 'A donation receipt can only be cancelled.';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_prevent_assoc_donation_receipt_mutation BEFORE UPDATE OR DELETE ON assoc_donation_receipts
                FOR EACH ROW EXECUTE FUNCTION prevent_assoc_donation_receipt_mutation();
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_prevent_assoc_donation_receipt_mutation ON assoc_donation_receipts;
                DROP FUNCTION IF EXISTS prevent_assoc_donation_receipt_mutation();
            ');
        }

        Schema::dropIfExists('assoc_sponsor_contracts');
        Schema::dropIfExists('assoc_sponsors');
        Schema::dropIfExists('assoc_five_per_mille_expenses');
        Schema::dropIfExists('assoc_five_per_mille_allocations');
        Schema::dropIfExists('assoc_expense_categories');
        Schema::dropIfExists('assoc_campaign_expenses');
        Schema::dropIfExists('assoc_donation_receipts');
        Schema::dropIfExists('assoc_donations');
        Schema::dropIfExists('assoc_donors');

        Schema::table('assoc_settings', function (Blueprint $table): void {
            $table->dropColumn(['donation_receipt_prefix', 'donation_revenue_account_id', 'five_per_mille_revenue_account_id']);
        });
    }
};
