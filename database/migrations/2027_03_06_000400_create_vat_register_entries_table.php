<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Protocol numbers of the VAT registers, assigned to the documents of a period when its settlement is posted.
     */
    public function up(): void
    {
        Schema::create('vat_register_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('vat_register_id');
            $table->uuid('journal_entry_id');
            $table->uuid('vat_settlement_id')->index();
            $table->integer('protocol_number');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('vat_register_id')->references('id')->on('vat_registers')->restrictOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
            $table->foreign('vat_settlement_id')->references('id')->on('vat_settlements')->restrictOnDelete();
            $table->unique(['vat_register_id', 'protocol_number'], 'uq_vat_register_protocol');
            $table->unique(['vat_register_id', 'journal_entry_id'], 'uq_vat_register_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_register_entries');
    }
};
