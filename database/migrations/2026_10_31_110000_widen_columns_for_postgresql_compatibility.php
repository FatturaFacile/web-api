<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->string('reconciliation_type', 50)->nullable()->change();
            $table->string('reconciliation_category', 50)->nullable()->change();
        });

        Schema::table('invoice_qr_bill_data', function (Blueprint $table): void {
            $table->string('scor_reference', 50)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->string('reconciliation_type', 20)->nullable()->change();
            $table->string('reconciliation_category', 30)->nullable()->change();
        });

        Schema::table('invoice_qr_bill_data', function (Blueprint $table): void {
            $table->string('scor_reference', 27)->change();
        });
    }
};
