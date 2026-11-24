<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The product runs on any database, so immutability is enforced in the application (see
     * App\Support\Database\Concerns\ProtectsRecords and the guards of the models). A PostgreSQL database migrated
     * before this change still carries the triggers of the older migrations: they are dropped here, with the functions
     * they call, because their session switches (app.payroll_reopen, app.time_audit_purge) no longer exist.
     */
    private const array FUNCTIONS = [
        'payroll_prevent_history_mutation',
        'payroll_protect_closed_payslip',
        'payroll_protect_closed_payslip_line',
        'payroll_protect_closed_run',
        'preservation_append_only_guard',
        'preservation_items_guard',
        'preservation_packages_guard',
        'prevent_assoc_access_log_mutation',
        'prevent_assoc_approved_minutes_mutation',
        'prevent_assoc_ballot_mutation',
        'prevent_assoc_donation_receipt_mutation',
        'prevent_assoc_lex398_entry_mutation',
        'prevent_audit_ledger_archive_mutation',
        'prevent_audit_ledger_mutation',
        'prevent_customs_assessment_mutation',
        'prevent_issued_invoice_item_mutation',
        'prevent_issued_invoice_mutation',
        'prevent_posted_journal_line_mutation',
        'prevent_posted_journal_mutation',
        'prevent_time_audit_mutation',
        'prevent_vat_settlement_mutation',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::FUNCTIONS as $function) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}() CASCADE");
        }
    }

    public function down(): void
    {
        // The triggers are not restored: the application enforces the rules.
    }
};
