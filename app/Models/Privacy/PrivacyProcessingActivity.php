<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class PrivacyProcessingActivity extends BaseEnterpriseModel
{
    protected $table = 'privacy_processing_activities';

    protected function casts(): array
    {
        return [
            'data_categories' => 'array',
            'subject_categories' => 'array',
            'recipients' => 'array',
            'cross_border_countries' => 'array',
            'version' => 'integer',
        ];
    }
}
