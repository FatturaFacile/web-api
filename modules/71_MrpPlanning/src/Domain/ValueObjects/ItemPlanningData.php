<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\ValueObjects;

use Modules\MrpPlanning\Domain\Enums\LotSizingRule;

/**
 * Everything the engine needs to plan one item: stock position, lot-sizing and scrap rules, lead-time inputs, the
 * effective bill of materials (Mod 060) and routing (Mod 061). supplierId is the key of the supplier's shutdown calendar.
 */
final readonly class ItemPlanningData
{
    public const string Make = 'make';

    public const string Buy = 'buy';

    public const string Subcontract = 'subcontract';

    /**
     * @param  string  $procurement  make | buy | subcontract
     * @param  list<BomLine>  $components
     * @param  list<RoutingOperation>  $operations
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $procurement,
        public int $leadTimeDays,
        public string $safetyStock = '0',
        public string $onHand = '0',
        public string $allocated = '0',
        public string $scrapPct = '0',
        public LotSizingRule $lotRule = LotSizingRule::LotForLot,
        public string $moq = '0',
        public string $multiple = '0',
        public string $eoq = '0',
        public int $fixedDays = 0,
        public ?string $supplierId = null,
        public ?string $supplierCountry = null,
        public ?int $customsDays = null,
        public ?string $bomVersionId = null,
        public array $components = [],
        public array $operations = [],
        public string $uom = 'PCS',
    ) {}
}
