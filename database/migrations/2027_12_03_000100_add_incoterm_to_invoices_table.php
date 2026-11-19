<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('incoterm', 3)->nullable();
            $table->string('incoterm_place', 100)->nullable();
            $table->decimal('freight_amount', 15, 2)->nullable();
            $table->decimal('insurance_amount', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['incoterm', 'incoterm_place', 'freight_amount', 'insurance_amount']);
        });
    }
};
