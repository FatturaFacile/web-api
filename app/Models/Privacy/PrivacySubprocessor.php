<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class PrivacySubprocessor extends BaseEnterpriseModel
{
    protected $table = 'privacy_subprocessors';

    protected function casts(): array
    {
        return [
            'is_adequate_country_nlpd' => 'boolean',
            'dpa_signed_at' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }
}
