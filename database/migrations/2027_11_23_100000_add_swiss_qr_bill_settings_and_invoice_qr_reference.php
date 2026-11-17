<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // 6-digit prefix prepended to the invoice sequence when a QRR reference is derived.
            $table->string('qrr_prefix', 6)->nullable();
            $table->string('default_reference_type', 4)->default('QRR');
            $table->string('qr_bill_perforation', 30)->default('DASHED_LINE_WITH_SCISSORS');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('qr_reference', 27)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['qr_reference']);
            $table->dropColumn('qr_reference');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['qrr_prefix', 'default_reference_type', 'qr_bill_perforation']);
        });
    }
};
