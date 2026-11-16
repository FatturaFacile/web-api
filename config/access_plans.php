<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Access limits per subscription plan
|--------------------------------------------------------------------------
|
| Users (active members plus pending invitations) and custom roles a tenant may have on each plan; null means
| unlimited. A platform administrator can override both numbers for a single tenant (tenants.max_users and
| tenants.max_custom_roles), and the override wins over the plan.
|
*/

return [
    'default' => 'standard',

    'plans' => [
        'starter' => ['max_users' => 3, 'max_custom_roles' => 0],
        'standard' => ['max_users' => 25, 'max_custom_roles' => 5],
        'professional' => ['max_users' => 100, 'max_custom_roles' => 25],
        'enterprise' => ['max_users' => null, 'max_custom_roles' => null],
    ],

    'invitation_ttl_days' => 7,

    'import_max_rows' => 500,
];
