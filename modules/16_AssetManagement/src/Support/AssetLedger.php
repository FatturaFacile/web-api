<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Support;

/**
 * The parallel books an asset is kept in. The statutory (civil) book is the one of the asset master record and the
 * only one posted to the General Ledger by default; the tax (fiscal) book tracks the depreciation deducted for tax
 * purposes and is reconciled in the tax return, not posted. "civil" and "fiscal" are accepted as aliases.
 */
final class AssetLedger
{
    public const string Statutory = 'statutory';

    public const string Tax = 'tax';

    public const string Ifrs = 'ifrs';

    public const string Management = 'management';

    /**
     * Every canonical ledger type.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::Statutory, self::Tax, self::Ifrs, self::Management];
    }

    /**
     * Canonical ledger types plus the civil and fiscal aliases accepted by the API.
     *
     * @return list<string>
     */
    public static function accepted(): array
    {
        return [...self::all(), 'civil', 'fiscal'];
    }

    /**
     * Maps the civil and fiscal aliases to the canonical ledger type.
     */
    public static function normalize(?string $ledgerType): string
    {
        return match ($ledgerType) {
            null, '', 'civil' => self::Statutory,
            'fiscal' => self::Tax,
            default => $ledgerType,
        };
    }
}
