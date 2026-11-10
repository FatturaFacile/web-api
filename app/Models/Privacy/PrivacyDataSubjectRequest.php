<?php

declare(strict_types=1);

namespace App\Models\Privacy;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class PrivacyDataSubjectRequest extends BaseEnterpriseModel
{
    protected $table = 'privacy_data_subject_requests';

    protected function casts(): array
    {
        return [
            'deadline_at' => 'immutable_datetime',
            'extended_until' => 'immutable_datetime',
            'legal_holds' => 'array',
            'erasure_summary' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
