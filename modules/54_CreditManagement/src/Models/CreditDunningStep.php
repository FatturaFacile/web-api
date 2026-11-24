<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One step of a dunning strategy: what to do and how many days after the due date it becomes due.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $strategy_id
 * @property int $step_order
 * @property string $name
 * @property int $days_after_due
 * @property string $channel
 * @property string|null $template_key
 * @property string $fee_amount
 * @property bool $escalates
 */
final class CreditDunningStep extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Channels = ['email', 'letter', 'phone', 'registered_letter', 'legal'];

    protected $table = 'credit_dunning_steps';

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'days_after_due' => 'integer',
            'fee_amount' => 'decimal:2',
            'escalates' => 'boolean',
        ];
    }
}
