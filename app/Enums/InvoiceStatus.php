<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\DocumentStatusMachine;

/**
 * Status of a sales document of the `invoices` table (invoices, credit and debit notes, proformas), written only by the
 * invoicing services of the app and of the invoicing module.
 *
 * draft ─► approved ─► posted
 *   └──────────────────►┘
 *
 * A draft carries a placeholder number; the emission (posting) gives it the gapless fiscal number and its ledger entry
 * in one transaction, after which the document is final: it is corrected with a credit or debit note, never edited. A
 * proforma is not a fiscal document and stays a draft until it is converted into an invoice.
 */
enum InvoiceStatus: string
{
    use DocumentStatusMachine;

    case Draft = 'draft';
    case Approved = 'approved';
    case Posted = 'posted';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Approved, self::Posted],
            self::Approved => [self::Posted],
            self::Posted => [],
        };
    }
}
