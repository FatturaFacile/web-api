<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds to the VAT settlements the figures of the Swiss statement and of the Italian liquidation, and admits the
     * half-yearly period of the Swiss effective method.
     */
    public function up(): void
    {
        Schema::table('vat_settlements', function (Blueprint $table): void {
            $table->string('country', 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('turnover', 15, 2)->default(0);
            $table->decimal('taxable_turnover', 15, 2)->default(0);
            $table->decimal('passive_operations', 15, 2)->default(0);
            $table->decimal('previous_debit', 15, 2)->default(0);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->boolean('below_minimum_payment')->default(false);
            $table->json('rate_breakdown')->nullable();
            $table->json('warnings')->nullable();
            $table->text('details')->nullable();
            $table->uuid('previous_settlement_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->uuid('paid_by')->nullable();
            $table->uuid('payment_journal_id')->nullable();

            $table->index(['tenant_id', 'period_from', 'period_to'], 'idx_vat_settlements_tenant_period');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vat_settlements DROP CONSTRAINT IF EXISTS vat_settlements_period_type_check');
            DB::statement("ALTER TABLE vat_settlements ADD CONSTRAINT vat_settlements_period_type_check CHECK (period_type IN ('monthly', 'quarterly', 'semiannual'))");

            return;
        }

        Schema::table('vat_settlements', function (Blueprint $table): void {
            $table->string('period_type', 20)->default('monthly')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("DELETE FROM vat_settlements WHERE period_type = 'semiannual'");
            DB::statement('ALTER TABLE vat_settlements DROP CONSTRAINT IF EXISTS vat_settlements_period_type_check');
            DB::statement("ALTER TABLE vat_settlements ADD CONSTRAINT vat_settlements_period_type_check CHECK (period_type IN ('monthly', 'quarterly'))");
        }

        Schema::table('vat_settlements', function (Blueprint $table): void {
            $table->dropIndex('idx_vat_settlements_tenant_period');
            $table->dropColumn([
                'country', 'currency', 'period_from', 'period_to', 'turnover', 'taxable_turnover', 'passive_operations',
                'previous_debit', 'interest_amount', 'below_minimum_payment', 'rate_breakdown', 'warnings', 'details',
                'previous_settlement_id', 'posted_at', 'posted_by', 'paid_at', 'paid_by', 'payment_journal_id',
            ]);
        });
    }
};
