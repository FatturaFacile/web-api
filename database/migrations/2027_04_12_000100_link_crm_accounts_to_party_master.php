<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\CRM\Services\PartyMaster\AccountContactBackfill;

/**
 * Makes the contact (the party master used by invoices, quotes and orders) the single source of a CRM account's master
 * data: every account gets a contact. The schema change is additive (a decision log table and an index), and the
 * backfill only fills empty `crm_accounts.contact_id` values, so the migration can run again without side effects.
 * Ambiguous matches are written to `crm_party_link_logs` instead of being guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_party_link_logs')) {
            Schema::create('crm_party_link_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('account_id')->constrained('crm_accounts')->cascadeOnDelete();
                $table->string('outcome', 20); // linked, created, ambiguous, resolved
                $table->string('matched_on', 100)->nullable();
                $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->jsonb('candidate_contact_ids')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique('account_id');
                $table->index(['tenant_id', 'outcome']);
            });
        }

        $hasContactIndex = collect(Schema::getIndexes('crm_accounts'))
            ->contains(fn (array $index): bool => $index['columns'] === ['tenant_id', 'contact_id']);

        if (! $hasContactIndex) {
            Schema::table('crm_accounts', function (Blueprint $table): void {
                $table->index(['tenant_id', 'contact_id']);
            });
        }

        app(AccountContactBackfill::class)->run();
    }

    public function down(): void
    {
        // The links written by the backfill are business data and are kept; only the decision log is dropped.
        Schema::dropIfExists('crm_party_link_logs');

        $hasContactIndex = collect(Schema::getIndexes('crm_accounts'))
            ->contains(fn (array $index): bool => $index['columns'] === ['tenant_id', 'contact_id']);

        if ($hasContactIndex) {
            Schema::table('crm_accounts', function (Blueprint $table): void {
                $table->dropIndex(['tenant_id', 'contact_id']);
            });
        }
    }
};
