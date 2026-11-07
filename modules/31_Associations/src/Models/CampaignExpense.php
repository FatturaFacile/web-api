<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A cost of a campaign, posted to the ledger on the cost centre of the campaign.
 *
 * @property string $amount
 */
final class CampaignExpense extends AssociationRecord
{
    protected $table = 'assoc_campaign_expenses';

    protected function casts(): array
    {
        return ['incurred_on' => 'date', 'amount' => 'decimal:2'];
    }
}
