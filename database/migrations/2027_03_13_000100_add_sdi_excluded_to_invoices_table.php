<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks internal documents, such as association fee receipts, that must never reach the SDI.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('sdi_excluded')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('sdi_excluded');
        });
    }
};
