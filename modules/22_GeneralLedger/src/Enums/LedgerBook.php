<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Enums;

use InvalidArgumentException;

/**
 * The parallel accounting books of a tenant: 01 statutory (Swiss OR/FER or Italian OIC), 02 tax and 03 international
 * (IFRS / US GAAP). The book is stored on a journal entry as its `ledger_type`.
 */
enum LedgerBook: string
{
    case Statutory = '01';
    case Tax = '02';
    case International = '03';

    public function ledgerType(): string
    {
        return match ($this) {
            self::Statutory => 'statutory',
            self::Tax => 'tax',
            self::International => 'ifrs',
        };
    }

    public static function fromLedgerType(string $ledgerType): self
    {
        return match ($ledgerType) {
            'tax' => self::Tax,
            'ifrs' => self::International,
            default => self::Statutory,
        };
    }

    /**
     * Resolves a book code (`01`) or a ledger type (`statutory`) to the book; null/empty means the statutory book.
     *
     * @throws InvalidArgumentException for an unknown book
     */
    public static function resolve(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Statutory;
        }

        return self::tryFrom($value) ?? match ($value) {
            'statutory' => self::Statutory,
            'tax' => self::Tax,
            'ifrs' => self::International,
            default => throw new InvalidArgumentException("Unknown ledger book [{$value}]."),
        };
    }

    /**
     * @return list<string>
     */
    public static function accepted(): array
    {
        return ['01', '02', '03', 'statutory', 'tax', 'ifrs'];
    }
}
