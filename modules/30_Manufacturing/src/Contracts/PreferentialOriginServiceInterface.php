<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\Models\MfgBom;

/**
 * Integration contract of the preferential origin test of a manufactured product (EUR.1, Switzerland-EU free trade
 * agreement) for the customs module (Mod 031): the value of the non-originating materials of the whole multi-level BOM
 * against the ex-works price.
 */
interface PreferentialOriginServiceInterface
{
    /** Share of the ex-works price that non-originating materials may reach, in percent. */
    public const string DefaultThresholdPercent = '50';

    /**
     * Evaluates the revision on a date.
     *
     * @param  string|null  $thresholdPercent  maximum non-originating share in percent, the agreement default when null
     * @return array{bom_id: string, item_id: string, quantity: string, exw_price: string, originating_value: string, non_originating_value: string, non_originating_percentage: string, threshold_percentage: string, eligible: bool, lines: list<array<string, mixed>>}
     */
    public function evaluate(MfgBom $bom, ?string $date = null, ?string $thresholdPercent = null): array;
}
