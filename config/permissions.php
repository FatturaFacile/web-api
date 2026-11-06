<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Module access
|--------------------------------------------------------------------------
|
| Who can read (GET, HEAD) and write (everything else) in each module, by role of the user in the active tenant. The
| * role means every member. A single user can also be granted "<module>.read", "<module>.write" or "*" in the
| permissions of the tenant_user pivot, without changing the role. The optional "grants" key of a module lists further
| permission names that open its read or write access.
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
        // A granular grant opens only the access listed here: the policies of the module do not narrow it further.
        'ledger' => [
            'read' => $finance,
            'write' => $finance,
            'grants' => ['read' => ['gl.*', 'gl.journals.post'], 'write' => ['gl.*']],
        ],
        'procurement' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'purchasing', 'procurement_manager', 'member']],
        'inventory' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'warehouse', 'purchasing', 'technician', 'member']],
        'hr' => ['read' => ['owner', 'admin', 'hr', 'payroll'], 'write' => ['owner', 'admin', 'hr', 'payroll']],
        // Personal data of members, minors included: closed to ordinary members. The accountant writes fees and payments
        // only, which the module checks by capability. Instructors, guardians and members use the member area.
        'associations' => ['read' => ['owner', 'admin', 'secretary', 'accountant'], 'write' => ['owner', 'admin', 'secretary', 'accountant']],
    ],
];
