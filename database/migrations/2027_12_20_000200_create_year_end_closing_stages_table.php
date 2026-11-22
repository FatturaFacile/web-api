<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stages of the year-end closing and opening workflow done per fiscal year and ledger book: 1 period lock, 2 net
 * result, 3 closing, 4 opening. A row exists only for a completed stage; the unique key makes every stage run once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('year_end_closing_stages')) {
            return;
        }

        Schema::create('year_end_closing_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('fiscal_year_id');
            $table->string('ledger_type', 20);
            $table->unsignedTinyInteger('stage');
            $table->string('stage_key', 30);
            $table->uuid('journal_entry_id')->nullable();
            $table->json('summary')->nullable();
            $table->uuid('executed_by')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['fiscal_year_id', 'ledger_type', 'stage'], 'uq_year_end_closing_stage');
            $table->foreign('fiscal_year_id')->references('id')->on('fiscal_years')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('year_end_closing_stages');
    }
};
