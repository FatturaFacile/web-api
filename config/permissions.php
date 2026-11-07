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
$financeReaders = [...$finance, 'accountant_readonly'];
$managers = ['owner', 'admin'];
$contractManagers = ['owner', 'admin', 'accountant', 'sales_manager', 'procurement_manager', 'service_manager'];

return [
    'modules' => [
        'crm' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager']],
        'invoicing' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales']],
        'subscriptions' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales']],
        'projects' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'project_manager', 'technician', 'member']],
        'assets' => ['read' => $anyMember, 'write' => $finance],
        'service' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'service_manager', 'technician', 'member']],
        'automotive' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'service_manager', 'technician', 'member']],
        'construction' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'project_manager', 'technician', 'member']],
        'professionals' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'project_manager', 'member']],
        'manufacturing' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'production', 'technician', 'member']],
        'treasury' => ['read' => $financeReaders, 'write' => $finance],
        // Backups hold every record of the company, and a restore replaces them.
        'system' => ['read' => ['owner', 'admin'], 'write' => ['owner', 'admin']],
        // A granular grant opens only the access listed here: the policies of the module do not narrow it further.
        'ledger' => [
            'read' => $financeReaders,
            'write' => $finance,
            'grants' => ['read' => ['gl.*', 'gl.journals.post'], 'write' => ['gl.*']],
        ],
        'procurement' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'purchasing', 'procurement_manager', 'member']],
        'inventory' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'warehouse', 'purchasing', 'technician', 'member']],
        'customs' => ['read' => ['owner', 'admin', 'accountant', 'customs', 'warehouse', 'sales'], 'write' => ['owner', 'admin', 'accountant', 'customs']],
        'documents' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales', 'purchasing', 'hr', 'member']],
        'contracts' => ['read' => $contractManagers, 'write' => $contractManagers],
        'assistant' => ['read' => $anyMember, 'write' => $anyMember],
        'edocument_networks' => ['read' => $finance, 'write' => $finance],
        'workspace' => ['read' => $anyMember, 'write' => $anyMember],
        'hr' => ['read' => ['owner', 'admin', 'hr', 'payroll'], 'write' => ['owner', 'admin', 'hr', 'payroll']],
        // Pay data of every employee: owners and administrators, or a member granted payroll.* on the membership (the
        // same rule as PayrollPolicy). Employees read their own payslips and certificates on routes without this check.
        'payroll' => [
            'read' => $managers,
            'write' => $managers,
            'grants' => ['read' => ['payroll.read', 'payroll.write', 'payroll.*'], 'write' => ['payroll.write', 'payroll.*']],
        ],
        // Anybody may be an approver, so every member starts, decides and follows approvals; the policies of the
        // workflow (initiator, assignee, owner or administrator) decide each action.
        'workflow' => ['read' => $anyMember, 'write' => $anyMember],
        'fiscal_retail' => ['read' => $financeReaders, 'write' => ['owner', 'admin', 'accountant', 'sales']],
        // Health data with fiscal codes: the read-only accountant role stays out.
        'health_expenses' => ['read' => $finance, 'write' => $finance],
        'exports' => ['read' => $financeReaders, 'write' => $finance],
        'reporting' => ['read' => $financeReaders, 'write' => $finance],
        'analytics' => ['read' => $financeReaders, 'write' => $finance],
        'communication' => ['read' => $financeReaders, 'write' => ['owner', 'admin', 'accountant', 'sales']],
        'document_trust' => ['read' => $financeReaders, 'write' => $finance],
        'integrations' => ['read' => $managers, 'write' => $managers],
        'payment_links' => ['read' => $financeReaders, 'write' => ['owner', 'admin', 'accountant', 'sales']],
        // Personal data of members, minors included: closed to ordinary members. The accountant writes fees and payments
        // only, which the module checks by capability. Instructors, guardians and members use the member area.
        'associations' => ['read' => ['owner', 'admin', 'secretary', 'accountant'], 'write' => ['owner', 'admin', 'secretary', 'accountant']],
        // Workshop staff work the folders; invoices and vehicle sales are reserved by the policy to the invoicing roles.
        'workshop' => [
            'read' => [...$financeReaders, 'sales', 'technician', 'service_manager'],
            'write' => [...$finance, 'sales', 'technician', 'service_manager'],
        ],
        // Site staff measure and book costs; billings are reserved by the policy to the invoicing roles.
        'construction_trades' => [
            'read' => [...$financeReaders, 'sales', 'project_manager', 'technician'],
            'write' => [...$finance, 'sales', 'project_manager', 'technician'],
        ],
        // Children's data: closed to everybody else. The policy splits care (educators), records (management) and
        // billing (management and accountant); the read-only accountant stays out.
        'childcare' => [
            'read' => ['owner', 'admin', 'childcare_manager', 'educator', 'accountant'],
            'write' => ['owner', 'admin', 'childcare_manager', 'educator', 'accountant'],
        ],
        // Hours and amounts of every professional and client: partners and finance only.
        'practice' => ['read' => $financeReaders, 'write' => $finance],
        // Waiting and kitchen staff work orders; the room plan and voids are reserved by the policy to managers.
        'hospitality' => [
            'read' => [...$financeReaders, 'sales', 'member'],
            'write' => ['owner', 'admin', 'sales', 'member'],
        ],
        // Every member announces their own guests; the policy reserves the register of visitors, check-in, the list of
        // who is on site and the kiosks to reception (owner, admin, receptionist). Read-only roles never write.
        'visitors' => ['read' => $anyMember, 'write' => $anyMember],
    ],

    /*
    | Members with these roles can never write, on any route. The check runs in the tenant middleware because the
    | routes of routes/api.php do not all declare a module, so leaving a role out of the write lists is not enough.
    */
    'read_only_roles' => ['accountant_readonly'],

    /*
    | What an API key may be granted, as "<module>.<read|write>". The key takes the place of the role in the module check.
    */
    'api_key_scopes' => ['reporting.read'],
];
