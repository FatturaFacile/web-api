<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('is_deposit')->default(false);
            $table->foreignUuid('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('signed_by', 100)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn('signed_by');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('quote_id');
            $table->dropColumn('is_deposit');
        });
    }
};
