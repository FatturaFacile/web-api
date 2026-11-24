<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\DocumentStatusMachine;

/**
 * Status of a delivery note (`delivery_notes.status`), written by the sales API of the app, the workshop and the
 * invoicing module. Where the goods are lives in `shipping_status`; this is the document.
 *
 * draft ──► issued ──► invoiced
 *   └──────────────────►┘
 *
 * The app issues its notes straight away; the module prepares a draft and issues it when the goods leave. An invoiced
 * note is final.
 */
enum DeliveryNoteStatus: string
{
    use DocumentStatusMachine;

    case Draft = 'draft';
    case Issued = 'issued';
    case Invoiced = 'invoiced';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::Issued, self::Invoiced],
            self::Issued => [self::Invoiced],
            self::Invoiced => [],
        };
    }
}
