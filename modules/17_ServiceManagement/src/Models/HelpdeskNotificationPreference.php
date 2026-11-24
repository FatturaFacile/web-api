<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Whether a user wants a helpdesk event by e-mail and in the in-app inbox.
 */
final class HelpdeskNotificationPreference extends BaseEnterpriseModel
{
    protected $table = 'esm_helpdesk_notification_preferences';

    protected function casts(): array
    {
        return [
            'mail' => 'boolean',
            'in_app' => 'boolean',
        ];
    }
}
