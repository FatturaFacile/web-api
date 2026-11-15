<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money tables that carried amounts without saying in which currency. The column stays nullable (no default: a
     * default of EUR is the very defect this fixes) and every existing row is backfilled from its parent record:
     * relations and intragroup transactions take the group currency of their consolidation group, statement lines the
     * currency of their statement, payslips the currency of their payroll run, and the VAT settlements that predate the
     * column the accounting currency of their company.
     */
    public function up(): void
    {
        $this->addColumn('gl_intercompany_relations');
        $this->addColumn('bank_statement_lines');
        $this->addColumn('payroll_payslips');

        foreach (DB::table('gl_consolidation_groups')->select(['id', 'group_currency'])->get() as $group) {
            foreach (['gl_intercompany_relations', 'gl_intragroup_transactions'] as $table) {
                if (Schema::hasColumn($table, 'currency')) {
                    DB::table($table)->where('group_id', $group->id)->update(['currency' => $group->group_currency]);
                }
            }
        }

        DB::table('bank_statement_lines')->whereNull('currency')->update([
            'currency' => DB::raw('(select currency from bank_statements where bank_statements.id = bank_statement_lines.bank_statement_id)'),
        ]);
        DB::table('bank_statement_lines')->whereNull('currency')->update(['currency' => 'EUR']);

        DB::table('payroll_payslips')->whereNull('currency')->update([
            'currency' => DB::raw('(select currency from payroll_runs where payroll_runs.id = payroll_payslips.payroll_run_id)'),
        ]);
        DB::table('payroll_payslips')->whereNull('currency')->update(['currency' => 'EUR']);

        DB::table('vat_settlements')->whereNull('currency')->update([
            'currency' => DB::raw('(select default_currency from tenants where tenants.id = vat_settlements.tenant_id)'),
        ]);
        DB::table('vat_settlements')->whereNull('currency')->update(['currency' => 'EUR']);
    }

    public function down(): void
    {
        foreach (['gl_intercompany_relations', 'bank_statement_lines', 'payroll_payslips'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('currency');
            });
        }
    }

    private function addColumn(string $table): void
    {
        if (Schema::hasColumn($table, 'currency')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->string('currency', 3)->nullable();
        });
    }
};
