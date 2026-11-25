<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\SalesBoundedContext\Domain\Enums\LeadLifecycleStage;

final class SbcOmnichannelLead extends BaseEnterpriseModel
{
    protected $table = 'sbc_omnichannel_leads';

    protected function casts(): array
    {
        return [
            'lifecycle_stage' => LeadLifecycleStage::class,
            'is_duplicate' => 'boolean',
            'demographic_score' => 'integer',
            'behavioral_score' => 'integer',
            'firmographic_score' => 'integer',
            'total_score' => 'integer',
            'utm_metadata' => 'array',
        ];
    }
}
