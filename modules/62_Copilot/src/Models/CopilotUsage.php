<?php

declare(strict_types=1);

namespace Modules\Copilot\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Tokens spent by one model call; the sum of the day is the tenant quota.
 *
 * @property string $provider
 * @property string|null $model
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $total_tokens
 */
final class CopilotUsage extends BaseEnterpriseModel
{
    protected $table = 'copilot_usages';
}
