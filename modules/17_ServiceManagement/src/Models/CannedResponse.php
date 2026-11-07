<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\ServiceManagement\Models\Concerns\SharedOrPersonal;

/**
 * A reusable reply text with placeholders such as {{ticket.number}} or {{requester.name}}.
 */
final class CannedResponse extends BaseEnterpriseModel
{
    use SharedOrPersonal;

    protected $table = 'esm_canned_responses';

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
        ];
    }
}
