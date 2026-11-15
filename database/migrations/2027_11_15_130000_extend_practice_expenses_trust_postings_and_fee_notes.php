<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expenses pulled into fee notes and their receipts, the general ledger posting and bank reconciliation of the client
     * funds ledger (kept apart because the ledger rows are append-only), and the reimbursed expenses of a fee note.
     */
    public function up(): void
    {
        Schema::table('fee_notes', function (Blueprint $table): void {
            $table->decimal('expenses_amount', 15, 2)->default(0)->after('vat_amount');
        });

        Schema::table('practice_expenses', function (Blueprint $table): void {
            $table->foreignUuid('fee_note_id')->nullable()->after('is_billed')->constrained('fee_notes')->nullOnDelete();
            $table->string('receipt_disk', 40)->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->string('receipt_name', 255)->nullable();
            $table->string('receipt_mime', 100)->nullable();
            $table->unsignedInteger('receipt_size')->nullable();
        });

        Schema::create('practice_trust_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('trust_transaction_id')->constrained('practice_trust_transactions')->restrictOnDelete();
            $table->uuid('journal_entry_id')->nullable()->index();
            $table->uuid('bank_statement_line_id')->nullable()->index();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'trust_transaction_id']);
        });

        Schema::table('practice_fee_proposals', function (Blueprint $table): void {
            $table->timestamp('expired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('practice_fee_proposals', function (Blueprint $table): void {
            $table->dropColumn('expired_at');
        });
        Schema::dropIfExists('practice_trust_postings');
        Schema::table('practice_expenses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fee_note_id');
            $table->dropColumn(['receipt_disk', 'receipt_path', 'receipt_name', 'receipt_mime', 'receipt_size']);
        });
        Schema::table('fee_notes', function (Blueprint $table): void {
            $table->dropColumn('expenses_amount');
        });
    }
};
