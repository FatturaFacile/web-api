<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Database\Concerns\ProtectsRecords;

/**
 * An issued (posted) invoice is corrected with a credit note, never rewritten: only its collection state and the
 * non-fiscal notes may still change.
 */
trait ProtectsIssuedInvoice
{
    use ProtectsRecords;

    /**
     * @var list<string>
     */
    private const array MUTABLE_AFTER_ISSUE = [
        'payment_status', 'total_paid', 'receivable_amount', 'due_date', 'notes', 'payment_method', 'payment_terms',
        'bank_name', 'bank_iban', 'gl_journal_entry_id', 'workflow_instance_id', 'updated_at',
        // Swiss eBill delivery summary (module SwissEbill): a transport state, not a fiscal field.
        'ebill_status', 'ebill_transmission_id', 'ebill_last_event_at',
    ];

    protected function recordProtectionViolation(string $operation): ?string
    {
        if ($operation === 'create' || $this->getOriginal('status') !== 'posted') {
            return null;
        }

        if ($operation === 'update' && ! $this->changesOutside(self::MUTABLE_AFTER_ISSUE)) {
            return null;
        }

        return 'An issued invoice is immutable: correct it with a credit note.';
    }
}
