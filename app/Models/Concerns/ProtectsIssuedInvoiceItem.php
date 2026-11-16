<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Database\Concerns\ProtectsRecords;
use Illuminate\Support\Facades\DB;

/**
 * The lines of an issued (posted) invoice are immutable.
 */
trait ProtectsIssuedInvoiceItem
{
    use ProtectsRecords;

    protected function recordProtectionViolation(string $operation): ?string
    {
        if ($operation === 'create') {
            return null;
        }

        $issued = DB::table('invoices')->where('id', $this->getOriginal('invoice_id'))->where('status', 'posted')->exists();

        return $issued ? 'The lines of an issued invoice are immutable.' : null;
    }
}
