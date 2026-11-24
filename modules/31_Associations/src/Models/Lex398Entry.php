<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use LogicException;

/**
 * An entry of the simplified register of commercial income kept under Law 398/1991. Append-only: a database trigger
 * refuses changes and deletions.
 *
 * @property string $taxable_amount
 * @property ?string $vat_rate
 * @property string $vat_amount
 */
final class Lex398Entry extends AssociationEntry
{
    protected $table = 'assoc_lex398_entries';

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Register entries cannot be changed.'));
        self::deleting(static fn () => throw new LogicException('Register entries cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'taxable_amount' => 'decimal:2', 'vat_rate' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }
}
