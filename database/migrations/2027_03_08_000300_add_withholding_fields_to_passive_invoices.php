<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `withholdings` keeps each DatiRitenuta of the document: one invoice can carry both a withholding and a contribution.
     * Existing documents default to no withholding: files already received are not parsed again.
     */
    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->decimal('withholding_tax_amount', 15, 2)->default(0);
            $table->jsonb('withholdings')->nullable();
            $table->uuid('declaration_of_intent_id')->nullable()->index();
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('document_language', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('document_language');
        });

        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropColumn(['withholding_tax_amount', 'withholdings', 'declaration_of_intent_id']);
        });
    }
};
