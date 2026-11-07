<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A helpdesk notification in the in-app inbox of a colleague.
 */
final class HelpdeskNotification extends BaseEnterpriseModel
{
    protected $table = 'esm_helpdesk_notifications';

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
