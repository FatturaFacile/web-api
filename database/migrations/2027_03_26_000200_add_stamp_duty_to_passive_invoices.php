<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stamp duty of DatiBollo on a received document. Existing documents default to none: files already received
     * are not parsed again.
     */
    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->decimal('stamp_duty_amount', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropColumn('stamp_duty_amount');
        });
    }
};
