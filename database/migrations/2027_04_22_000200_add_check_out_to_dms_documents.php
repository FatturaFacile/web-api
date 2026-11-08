<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Check-out of a document: while somebody has it checked out nobody else can add a version or change it. Every column
     * is nullable, so existing documents are simply not checked out.
     */
    public function up(): void
    {
        Schema::table('dms_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('dms_documents', 'checked_out_by')) {
                $table->uuid('checked_out_by')->nullable()->index();
            }

            if (! Schema::hasColumn('dms_documents', 'checked_out_at')) {
                $table->timestampTz('checked_out_at')->nullable();
            }

            if (! Schema::hasColumn('dms_documents', 'checkout_note')) {
                $table->string('checkout_note', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dms_documents', function (Blueprint $table): void {
            $table->dropColumn(['checked_out_by', 'checked_out_at', 'checkout_note']);
        });
    }
};
