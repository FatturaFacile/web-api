<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class PostingTemplate extends BaseEnterpriseModel
{
    protected $table = 'posting_templates';

    protected function casts(): array
    {
        return [
            'template_payload' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
