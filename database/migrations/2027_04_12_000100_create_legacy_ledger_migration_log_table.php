<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outcome of copying each legacy ledger entry (`general_ledger_entries`) into the journal of the General Ledger module:
 * migrated (with its journal entry) or skipped with the reason, e.g. a closed period that is not reopened. One row per
 * legacy entry, kept up to date by every run of App\Services\Accounting\LegacyLedgerMigrator. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legacy_ledger_migration_log')) {
            return;
        }

        Schema::create('legacy_ledger_migration_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('general_ledger_entry_id')->unique();
            $table->string('entry_number', 50);
            $table->date('entry_date');
            $table->string('status', 20)->index();
            $table->string('reason', 40)->nullable()->index();
            $table->text('message')->nullable();
            $table->uuid('journal_entry_id')->nullable()->index();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_ledger_migration_log');
    }
};
