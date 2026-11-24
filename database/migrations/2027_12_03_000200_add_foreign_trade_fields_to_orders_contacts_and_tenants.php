<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('incoterm', 3)->nullable();
            $table->string('incoterm_place', 100)->nullable();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('default_incoterm', 3)->nullable();
            $table->string('eori_number', 17)->nullable();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('eori_number', 17)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn(['incoterm', 'incoterm_place']);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn(['default_incoterm', 'eori_number']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('eori_number');
        });
    }
};
