<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #286: annual minimum contribution of a year and agent type, next to the rate and the annual ceiling. Zero means
 * the year has no minimum.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('enasarco_parameters', 'minimum_contribution')) {
            return;
        }

        Schema::table('enasarco_parameters', function (Blueprint $table): void {
            $table->decimal('minimum_contribution', 15, 2)->default(0)->after('annual_ceiling');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('enasarco_parameters', 'minimum_contribution')) {
            Schema::table('enasarco_parameters', function (Blueprint $table): void {
                $table->dropColumn('minimum_contribution');
            });
        }
    }
};
