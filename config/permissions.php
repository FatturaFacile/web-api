<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Module access
|--------------------------------------------------------------------------
|
| Who can read (GET, HEAD) and write (everything else) in each module, by role of the user in the active tenant. The
| * role means every member. A single user can also be granted "<module>.read", "<module>.write" or "*" in the
| permissions of the tenant_user pivot, without changing the role.
|
*/

$anyMember = ['*'];
$finance = ['owner', 'admin', 'accountant'];

return [
    'modules' => [
        'crm' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager']],
        'invoicing' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales']],
        'subscriptions' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales']],
        'projects' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'project_manager', 'technician', 'member']],
        'assets' => ['read' => $anyMember, 'write' => $finance],
        'service' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'service_manager', 'technician', 'member']],
        'manufacturing' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'production', 'technician', 'member']],
        'treasury' => ['read' => $finance, 'write' => $finance],
        // Backups hold every record of the company, and a restore replaces them.
        'system' => ['read' => ['owner', 'admin'], 'write' => ['owner', 'admin']],
        'ledger' => ['read' => $finance, 'write' => $finance],
        'procurement' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'purchasing', 'procurement_manager', 'member']],
        'inventory' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'warehouse', 'purchasing', 'technician', 'member']],
        'hr' => ['read' => ['owner', 'admin', 'hr', 'payroll'], 'write' => ['owner', 'admin', 'hr', 'payroll']],
    ],
];
