<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Something done on a collection case: a dunning step or a free action such as a call or a note.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $case_id
 * @property string|null $step_id
 * @property string $action_type
 * @property string|null $channel
 * @property Carbon $performed_on
 * @property string|null $performed_by
 * @property string $fee_amount
 * @property string|null $outcome
 */
final class CreditCollectionAction extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Types = ['dunning_step', 'call', 'email', 'letter', 'visit', 'legal', 'note'];

    protected $table = 'credit_collection_actions';

    protected function casts(): array
    {
        return [
            'performed_on' => 'date',
            'fee_amount' => 'decimal:2',
        ];
    }
}
