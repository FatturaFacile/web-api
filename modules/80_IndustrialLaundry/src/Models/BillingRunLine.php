<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\BillingLineKind;

/**
 * @property string $billing_run_id
 * @property int $position
 * @property BillingLineKind $kind
 * @property ArticleType|null $article_type
 * @property string $quantity
 * @property string $uom
 * @property string $unit_price
 * @property string $amount
 * @property array<string, mixed>|null $detail
 */
final class BillingRunLine extends BaseEnterpriseModel
{
    protected $table = 'ilr_billing_run_lines';

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => BillingLineKind::class,
            'article_type' => ArticleType::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'amount' => 'decimal:4',
            'detail' => 'array',
        ];
    }
}
