<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A fundraising campaign, regular or occasional, with its own reporting. Its cost centre separates its postings.
 *
 * @property string $id
 * @property string $kind
 * @property ?string $cost_center_id
 * @property ?string $revenue_account_id
 * @property ?string $expense_account_id
 */
final class Campaign extends AssociationRecord
{
    public const string Regular = 'regular';

    public const string Occasional = 'occasional';

    protected $table = 'assoc_campaigns';

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'target_amount' => 'decimal:2'];
    }
}
