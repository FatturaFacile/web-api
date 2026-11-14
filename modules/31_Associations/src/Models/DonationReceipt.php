<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use App\Support\Database\Concerns\ProtectsRecords;

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
    use ProtectsRecords;

    protected $table = 'assoc_donation_receipts';

    /**
     * @var list<string>
     */
    private const array FROZEN_COLUMNS = ['number', 'amount', 'issued_on', 'holder', 'donation_id'];

    protected function recordProtectionViolation(string $operation): ?string
    {
        return match ($operation) {
            'create' => null,
            'delete' => 'Donation receipts cannot be deleted.',
            default => $this->getOriginal('cancelled_at') !== null || $this->isDirty(self::FROZEN_COLUMNS) ? 'A donation receipt can only be cancelled.' : null,
        };
    }

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'amount' => 'decimal:2', 'holder' => 'encrypted:array', 'cancelled_at' => 'datetime', 'fiscal_year' => 'integer', 'sequential_number' => 'integer'];
    }
}
