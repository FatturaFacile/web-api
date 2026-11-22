<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MES Core profile of a machine: how its tags map to roles, the signal conditioning and the OEE policy. The MES Core
 * reads it from the `machines` feed, next to the ideal cycle time and the line of the machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mfg_machine_centers', 'mes_profile')) {
            Schema::table('mfg_machine_centers', function (Blueprint $table): void {
                $table->json('mes_profile')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mfg_machine_centers', 'mes_profile')) {
            Schema::table('mfg_machine_centers', function (Blueprint $table): void {
                $table->dropColumn('mes_profile');
            });
        }
    }
};
