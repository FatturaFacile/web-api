<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money rows that carried an amount without saying in which currency: supplier payments take the currency of the
     * invoice they pay, intragroup transactions the currency of the seller (EUR when unknown).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('supplier_payments', 'currency')) {
            Schema::table('supplier_payments', function (Blueprint $table): void {
                $table->string('currency', 3)->nullable()->after('amount');
            });
        }

        DB::table('supplier_payments')->whereNull('currency')->update([
            'currency' => DB::raw('(select currency from passive_invoices where passive_invoices.id = supplier_payments.passive_invoice_id)'),
        ]);
        DB::table('supplier_payments')->whereNull('currency')->update(['currency' => 'EUR']);

        if (! Schema::hasColumn('gl_intragroup_transactions', 'currency')) {
            Schema::table('gl_intragroup_transactions', function (Blueprint $table): void {
                $table->string('currency', 3)->default('EUR')->after('transaction_date');
            });
        }
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
        Schema::table('gl_intragroup_transactions', function (Blueprint $table): void {
            $table->dropColumn('currency');
        });
    }
};
