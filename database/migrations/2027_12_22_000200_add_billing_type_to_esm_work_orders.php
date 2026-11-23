<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('esm_work_orders', 'billing_type')) {
            return;
        }

        Schema::table('esm_work_orders', function (Blueprint $table): void {
            $table->string('billing_type', 30)->default('contract_included');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('esm_work_orders', 'billing_type')) {
            return;
        }

        Schema::table('esm_work_orders', function (Blueprint $table): void {
            $table->dropColumn('billing_type');
        });
    }
};
