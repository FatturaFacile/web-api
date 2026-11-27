<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\LedgerMovementType;

/**
 * Immutable register line of the goods held by a subcontractor (or, for active jobs, the customer goods held by us): once
 * written it is never changed or deleted, corrections are new lines. Customer-owned goods always carry a valuation of 0.00.
 *
 * @property Direction $direction
 * @property string $subcontracting_order_id
 * @property string $partner_id
 * @property string|null $virtual_warehouse_id
 * @property string $component_product_id
 * @property string|null $lot_id
 * @property LedgerMovementType $movement_type
 * @property Carbon $entry_date
 * @property string $dispatched_qty
 * @property string $consumed_in_returned_goods_qty
 * @property string $scrap_reported_by_subcontractor_qty
 * @property string $returned_unused_qty
 * @property string $remaining_at_subcontractor_qty
 * @property string|null $ddt_document_id
 * @property string|null $ddt_number
 * @property Carbon|null $ddt_date
 * @property string|null $transport_reason
 * @property Carbon|null $statutory_return_deadline
 * @property string|null $customs_declaration_id
 * @property string $valuation_amount
 * @property string|null $notes
 */
final class SubcontractMaterialLedgerEntry extends BaseEnterpriseModel
{
    protected $table = 'sct_material_ledger_entries';

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('The subcontracting ledger is immutable: post a correcting entry instead.');
        });
        self::deleting(static function (): never {
            throw new LogicException('The subcontracting ledger is immutable: post a correcting entry instead.');
        });
    }

    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'movement_type' => LedgerMovementType::class,
            'entry_date' => 'date',
            'ddt_date' => 'date',
            'statutory_return_deadline' => 'date',
            'dispatched_qty' => 'decimal:4',
            'consumed_in_returned_goods_qty' => 'decimal:4',
            'scrap_reported_by_subcontractor_qty' => 'decimal:4',
            'returned_unused_qty' => 'decimal:4',
            'remaining_at_subcontractor_qty' => 'decimal:4',
            'valuation_amount' => 'decimal:2',
        ];
    }
}
