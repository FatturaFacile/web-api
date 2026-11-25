<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A customer who does not want surveys (every channel when the channel is null).
 *
 * @property string $customer_id
 * @property string|null $channel
 * @property string|null $reason
 */
final class SurveyOptOut extends BaseEnterpriseModel
{
    protected $table = 'survey_opt_outs';
}
