<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class PrivacySensitiveAccessLog extends BaseEnterpriseModel
{
    protected $table = 'privacy_sensitive_access_logs';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
