<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use LogicException;

/**
 * The numbered receipt of a donation, with a snapshot of the donor at issue. A database trigger allows only its
 * cancellation; it is never deleted or rewritten.
 *
 * @property string $id
 * @property string $number
 * @property string $amount
 * @property array<string, ?string> $holder
 * @property ?string $cancelled_at
 */
final class DonationReceipt extends AssociationEntry
{
    protected $table = 'assoc_donation_receipts';

    protected static function booted(): void
    {
        self::deleting(static fn () => throw new LogicException('Donation receipts cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'amount' => 'decimal:2', 'holder' => 'encrypted:array', 'cancelled_at' => 'datetime', 'fiscal_year' => 'integer', 'sequential_number' => 'integer'];
    }
}
