<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payslip link sent again replaces the previous ones: they are marked revoked instead of being deleted, so the
     * history of what was sent stays. Nullable column without default: no existing row is rewritten.
     */
    public function up(): void
    {
        Schema::table('payroll_payslip_deliveries', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable()->after('failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_payslip_deliveries', function (Blueprint $table): void {
            $table->dropColumn('revoked_at');
        });
    }
};
