<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Access management
|--------------------------------------------------------------------------
|
| Plans and the default limits they put on the people of a company. A null limit is unlimited. The platform
| administrator can override max_users and max_custom_roles per tenant (PUT /admin/tenants/{id}/plan).
|
*/

return [
    'plans' => [
        'starter' => ['max_users' => 3, 'max_custom_roles' => 0],
        'standard' => ['max_users' => 10, 'max_custom_roles' => 3],
        'professional' => ['max_users' => 50, 'max_custom_roles' => 20],
        'enterprise' => ['max_users' => null, 'max_custom_roles' => null],
    ],

    // Days an invitation stays valid.
    'invitation_ttl_days' => 7,

    // Rows of a user import file and its size in kilobytes.
    'import_max_rows' => 1000,
    'import_max_kilobytes' => 512,
];
