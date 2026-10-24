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
        Schema::table('payment_import_lines', function (Blueprint $table): void {
            $table->decimal('fee_amount', 15, 2)->default(0); // bank processing fees reported with the entry
            $table->string('reference_type', 10)->nullable(); // qr, pvr, scor, other
        });

        Schema::table('payment_imports', function (Blueprint $table): void {
            $table->decimal('total_fees', 15, 2)->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_imports', function (Blueprint $table): void {
            $table->dropColumn('total_fees');
        });

        Schema::table('payment_import_lines', function (Blueprint $table): void {
            $table->dropColumn(['fee_amount', 'reference_type']);
        });
    }
};
