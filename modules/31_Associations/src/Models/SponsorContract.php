<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A sponsorship contract: amount, period, what the association gives in return, and the signed document. The
 * invoice, when issued, is a document of the invoicing module and is only referenced here.
 */
final class SponsorContract extends AssociationRecord
{
    protected $table = 'assoc_sponsor_contracts';

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'amount' => 'decimal:2'];
    }
}
