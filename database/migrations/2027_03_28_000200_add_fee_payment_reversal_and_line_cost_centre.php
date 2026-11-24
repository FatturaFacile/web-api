<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The cost centre of an invoice line, carried to its revenue line in the ledger, and the reversal of an association
     * fee payment with the cancellation of its receipt. Every column is nullable: existing rows keep their meaning
     * (no cost centre, not reversed, not cancelled).
     */
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->foreignUuid('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
        });

        Schema::table('assoc_receipts', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
        });

        Schema::table('assoc_fee_payments', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->uuid('credit_note_id')->nullable();
            $table->uuid('reversed_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assoc_fee_payments', function (Blueprint $table): void {
            $table->dropColumn(['reversed_at', 'reversal_reason', 'credit_note_id', 'reversed_by']);
        });

        Schema::table('assoc_receipts', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cost_center_id');
        });
    }
};
