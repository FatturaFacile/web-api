<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #286: ENASARCO rate, annual minimum and commission ceiling per year. A row without tenant applies to every
 * tenant; a tenant row overrides it. Years without a row fall back to config('italian_tax.enasarco.years').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enasarco_annual_limits')) {
            return;
        }

        Schema::create('enasarco_annual_limits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('rate', 5, 2);
            $table->decimal('annual_minimum', 15, 2)->default(0);
            $table->decimal('annual_maximum_commission', 15, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'year']);
            $table->index('year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enasarco_annual_limits');
    }
};
