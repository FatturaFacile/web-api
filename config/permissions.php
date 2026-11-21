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
        // Sales managers approve or reject quotes when no approval workflow decides (SalesQuotePolicy::approve).
        'invoicing' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager']],
        'subscriptions' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales']],
        'projects' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'project_manager', 'technician', 'member']],
        'assets' => ['read' => $anyMember, 'write' => $finance],
        'service' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'service_manager', 'technician', 'member']],
        'automotive' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'service_manager', 'technician', 'member']],
        'construction' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'project_manager', 'technician', 'member']],
        'professionals' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'project_manager', 'member']],
        'manufacturing' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'production', 'technician', 'member']],
        'treasury' => ['read' => $financeReaders, 'write' => $finance],
        // Approval process definitions: every member reads them, owners and admins design them.
        'workflow_design' => ['read' => $anyMember, 'write' => $managers],
        // Backups hold every record of the company, and a restore replaces them.
        'system' => ['read' => ['owner', 'admin'], 'write' => ['owner', 'admin']],
        // The immutable audit trail: owners and administrators, or a member (an auditor) granted audit.* permissions on the
        // membership. Each endpoint then checks its own permission (audit.view, audit.verify, audit.export, audit.worm.manage).
        'audit_trail' => [
            'read' => $managers,
            'write' => $managers,
            'grants' => [
                'read' => ['audit.view', 'audit.verify', 'audit.export', 'audit.worm.manage', 'audit.*'],
                'write' => ['audit.verify', 'audit.worm.manage', 'audit.*'],
            ],
        ],
        // A granular grant opens only the access listed here: the policies of the module do not narrow it further.
        'ledger' => [
            'read' => $financeReaders,
            'write' => $finance,
            'grants' => ['read' => ['gl.*', 'gl.journals.post'], 'write' => ['gl.*']],
        ],
        'procurement' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'purchasing', 'procurement_manager', 'member']],
        // Valuation management and the inventory book export are granted one by one on the membership (see InventoryPermissionChecker), whatever the role.
        'inventory' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'warehouse', 'purchasing', 'technician', 'member'],
            'grants' => ['write' => ['inventory.valuation.manage', 'inventory.valuation.*', 'inventory.book.export', 'inventory.book.*', 'inventory.*']],
        ],
        'customs' => ['read' => ['owner', 'admin', 'accountant', 'customs', 'warehouse', 'sales'], 'write' => ['owner', 'admin', 'accountant', 'customs']],
        // Managers write too: they are named as approvers of document approval steps and must be able to decide them.
        'documents' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager', 'purchasing', 'procurement_manager', 'service_manager', 'hr', 'member']],
        'contracts' => ['read' => $contractManagers, 'write' => $contractManagers],
        'assistant' => ['read' => $anyMember, 'write' => $anyMember],
        // The Copilot answers and stages actions only within the permissions of the caller on each module it touches.
        'copilot' => ['read' => $anyMember, 'write' => $anyMember],
        // The Academy is open to every member: learning content and the own progress; teachers are the members that create cohorts.
        'academy' => ['read' => $anyMember, 'write' => $anyMember],
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
        'digital_preservation' => ['read' => $financeReaders, 'write' => $finance],
        // AI cash flow and demand forecasting (Mod 094). forecasting.view reads, forecasting.simulate trains and simulates; the
        // one-click apply of reorder thresholds to the warehouse and planning data is its own module (forecasting.apply_wms).
        'forecasting' => [
            'read' => [...$financeReaders, 'warehouse', 'purchasing', 'procurement_manager', 'sales_manager'],
            'write' => $finance,
            'grants' => ['read' => ['forecasting.view', 'forecasting.simulate', 'forecasting.*'], 'write' => ['forecasting.simulate', 'forecasting.*']],
        ],
        // AI Inbox and OCR extraction (Mod 092). ocr_inbox: the finance team reviews and confirms extracted documents;
        // ocr_capture: every member may send a receipt photo from the app; ocr_settings: owners and admins choose the OCR engine.
        'ocr_inbox' => [
            'read' => [...$financeReaders, 'purchasing', 'procurement_manager'],
            'write' => $finance,
            'grants' => ['read' => ['ocr_inbox.view', 'ocr_inbox.*'], 'write' => ['ocr_inbox.review', 'ocr_inbox.*']],
        ],
        'ocr_capture' => ['read' => $anyMember, 'write' => $anyMember],
        // Executive KPI dashboards (Mod 090). kpi_dashboards: any member opens the cockpits their role allows and saves their own
        // layouts (KpiAccess narrows each KPI by role, legal entity, cost center and compensation grant); kpi_dashboards_admin:
        // finance and managers configure threshold alerts and refresh the analytical cubes.
        'kpi_dashboards' => ['read' => $anyMember, 'write' => $anyMember],
        'kpi_dashboards_admin' => [
            'read' => $financeReaders,
            'write' => $finance,
            'grants' => ['read' => ['kpi.*'], 'write' => ['kpi.*']],
        ],
        'ocr_settings' => ['read' => $managers, 'write' => $managers],
        // Workplace health, safety and training (Mod 099). hse: the operational roles read the compliance matrix, the clearance verdict
        // and the physician judgments (outcome and prescriptions); the HSE team (hr, hse, admins) writes certificates, PPE and incidents.
        // hse_medical: who records physician judgments. hse_clinical: clinical notes belong to the occupational physician alone,
        // so owners and admins are deliberately absent (GDPR Art. 9 / nLPD).
        'hse' => [
            'read' => ['owner', 'admin', 'hr', 'hse', 'production', 'technician', 'service_manager', 'project_manager', 'accountant', 'member'],
            'write' => ['owner', 'admin', 'hr', 'hse'],
        ],
        'hse_medical' => ['read' => ['owner', 'admin', 'hr', 'hse', 'medico'], 'write' => ['owner', 'admin', 'hr', 'hse', 'medico']],
        'hse_clinical' => ['read' => ['medico'], 'write' => ['medico'], 'grants' => ['read' => ['hse.clinical.read'], 'write' => ['hse.clinical.write']]],
        // AI Services & Cognitive Automation Layer (Mod 093). ai_copilot (ai.copilot.use): every member asks the copilot and drafts
        // messages, each answer limited to what the caller may read; ai_review (ai.suggestions.review): the finance and sales teams
        // work the review queue and the insights; ai_admin (ai.admin.configure): owners and admins set up providers and policies and
        // read the usage and audit trail.
        'ai_copilot' => ['read' => $anyMember, 'write' => $anyMember, 'grants' => ['read' => ['ai.copilot.use', 'ai.*'], 'write' => ['ai.copilot.use', 'ai.*']]],
        'ai_review' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager', 'purchasing', 'procurement_manager', 'warehouse'],
            'write' => [...$finance, 'sales', 'sales_manager', 'purchasing', 'procurement_manager'],
            'grants' => ['read' => ['ai.suggestions.view', 'ai.suggestions.review', 'ai.*'], 'write' => ['ai.suggestions.review', 'ai.*']],
        ],
        'ai_admin' => ['read' => $managers, 'write' => $managers, 'grants' => ['read' => ['ai.admin.configure', 'ai.*'], 'write' => ['ai.admin.configure', 'ai.*']]],
        'forecasting_wms' => [
            'read' => $managers,
            'write' => ['owner', 'admin', 'warehouse', 'purchasing', 'procurement_manager'],
            'grants' => ['write' => ['forecasting.apply_wms', 'forecasting.*']],
        ],
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
        // INTRA lists of goods and services with other member states: finance and customs staff. The read-only
        // accountant reads them; read-only roles never write.
        'intrastat' => [
            'read' => [...$financeReaders, 'customs'],
            'write' => [...$finance, 'customs'],
        ],
        // Quality management (non-conformities, CAPA, audits, controlled procedures): every member reads and raises
        // quality issues; owners, administrators, production and service staff manage them. Approving a procedure is
        // reserved by the policy to owners, administrators and members granted quality.approve.
        'quality' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'production', 'technician', 'service_manager', 'procurement_manager', 'member'],
        ],
        // Governance, risk and compliance: every member reads the register and works on the remediation actions they own;
        // the policy reserves the register, the controls library, the audit plan and the calendar to owners, administrators,
        // accountants and members granted governance.manage, and the management decisions (accepting a risk, approving a
        // plan, verifying an action) to owners, administrators and members granted governance.approve.
        'governance' => ['read' => $anyMember, 'write' => $anyMember],
        // Business trips and travel expenses: every member requests and follows their own trips. The policy lets owners,
        // administrators, HR and accountants see every trip, approve (also travel.approve) and maintain the rate tables
        // (also travel.rates); owners, administrators and accountants (also travel.reconcile) record advances and reconcile.
        'travel' => ['read' => $anyMember, 'write' => $anyMember],
        // Configure-price-quote: every member reads the configurable models and configurations; sales staff configure
        // and quote. Models, options and rules are reserved by the policy to owners, administrators, sales managers and
        // members granted configurator.manage; approving a configuration below the minimum margin to owners,
        // administrators, sales managers and members granted configurator.approve.
        'configurator' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager'],
        ],
        // Supplier onboarding, qualification and bank detail changes: purchasing and finance staff only. Approving a
        // bank detail change is reserved by the policy to owners, administrators, accountants and members granted
        // supplier_portal.bank_approve; the requester never approves their own request.
        'supplier_portal' => [
            'read' => [...$financeReaders, 'purchasing', 'procurement_manager'],
            'write' => [...$finance, 'purchasing', 'procurement_manager'],
        ],
        // Lease accounting (IFRS 16, OIC, Swiss GAAP FER): right-of-use assets and lease liabilities are ledger data,
        // so the module is for finance only; the read-only accountant reads it and never writes.
        'leases' => ['read' => $financeReaders, 'write' => $finance],
        // ESG and carbon accounting: every member reads; owners, administrators, accountants and operational staff keep
        // the activity data. Reopening a closed period and approving a materiality assessment are reserved by the policy
        // to owners, administrators and members granted sustainability.approve.
        'sustainability' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'production', 'technician', 'procurement_manager', 'member'],
        ],
        // Facility management: every member books rooms and desks and raises service requests; the policy reserves
        // buildings, spaces, inspections and meters to facility managers and operators. Read-only roles never write.
        'facility' => ['read' => $anyMember, 'write' => $anyMember],
        // Planning and budgeting (controlling): every member reads; owners, administrators, accountants and department
        // staff keep versions, lines and drivers. Approving, rejecting and locking a version are reserved by the policy
        // to owners, administrators and members granted budgeting.approve.
        'budgeting' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'production', 'procurement_manager', 'sales_manager', 'member'],
        ],
        // Controlling (analytical cost accounting, margin control, working capital KPIs, budgets with rolling forecast):
        // finance, sales management and production read; owners, administrators and accountants keep cost centers, jobs,
        // allocation rules and plans. Running allocations needs controlling.allocations.run (owners, administrators and
        // accountants have it) and approving or locking a plan budgeting.approve, both reserved by the policy.
        'controlling' => [
            'read' => [...$financeReaders, 'sales_manager', 'production', 'procurement_manager'],
            'write' => [...$finance, 'production'],
        ],
        // Sales and operations planning (S&OP): every member reads; planners, production, purchasing and finance staff keep
        // the scenarios, plan lines and capacity data. Approving, rejecting, locking and publishing a plan are reserved by
        // the policy to owners, administrators and members granted planning.approve.
        'planning' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'production', 'procurement_manager', 'sales_manager', 'member'],
        ],
        // Fleet management (company vehicles): every member reads; owners, administrators, accountants and operational
        // staff keep vehicles, fuel, services, deadlines and fines. Read-only roles never write.
        'fleet' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'production', 'technician', 'procurement_manager', 'member'],
        ],
        // Business intelligence (dashboards, pivots, calculated measures, snapshots): every member reads what is shared with
        // them; the datasets themselves stay limited by the module of their data. Managers and finance design and share.
        'business_intelligence' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'sales_manager', 'procurement_manager', 'service_manager', 'project_manager', 'member'],
        ],
        // Platform: the recycle bin (restore or destroy deleted records) is for owners and administrators; the notification centre is personal and has no module rule.
        'platform' => ['read' => $managers, 'write' => $managers],
        // Marketplace (connectors holding credentials of external systems): only owners and administrators manage them.
        'marketplace' => ['read' => $managers, 'write' => $managers],
        // Event management (events, sessions, tickets, registrations, check-in): every member reads; owners,
        // administrators, accountants, sales and service staff keep them.
        'events' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager', 'service_manager', 'member'],
        ],
        // Product lifecycle management (change orders, item revisions): every member reads; owners, administrators,
        // production, technical and purchasing staff keep them. Deciding a change order is reserved to its approvers.
        'plm' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'production', 'technician', 'procurement_manager', 'member'],
        ],
        // Volume rebates and trade promotions: finance and sales roles read; owners, administrators, accountants, sales
        // managers and purchasing managers keep agreements (customers and suppliers), accruals, settlements and promotions.
        'rebates' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager', 'procurement_manager'],
            'write' => [...$finance, 'sales_manager', 'procurement_manager'],
        ],
        // Advanced pricing (price lists, cascading discounts, promotions, margin guardrails): finance and sales roles read;
        // owners, administrators, accountants and sales managers write. Authorizing a below-margin document is further
        // limited by the policy to owners, administrators and the role of the margin policy.
        'pricing' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager', 'procurement_manager'],
            'write' => [...$finance, 'sales_manager'],
        ],
        // Credit risk and collections: finance and sales roles read; owners, administrators, accountants and sales
        // managers score customers, work the cases and request limits. Deciding a credit limit request and writing a
        // case off are reserved by the policy to owners, administrators and members granted credit-management.approve.
        'credit-management' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager'],
            'write' => [...$finance, 'sales_manager'],
        ],
        // Advanced warehouse management (RF tasks, slotting, cross-docking, docks, waves): every member reads; owners,
        // administrators, accountants and warehouse, production and procurement staff run the floor. Read-only roles never write.
        'wms' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'production', 'warehouse', 'technician', 'procurement_manager', 'purchasing', 'member'],
        ],
        // Transportation management (carriers, tariffs, transport orders, trips, quotes, freight audit): every member
        // reads; owners, administrators, accountants and logistics, sales and procurement staff keep the records.
        'transportation' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'warehouse', 'sales', 'sales_manager', 'purchasing', 'procurement_manager', 'production', 'member'],
        ],
        // Talent and learning, learning side: every member reads the catalogue and answers the open surveys; owners,
        // administrators and HR keep courses, paths, enrolments and surveys. The policy reserves the lists that show other
        // employees (enrolments, certificates, compliance, survey results) to those roles and to members granted
        // talent_learning.manage.
        'talent_learning' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'hr']],
        // Talent and learning, planning side: succession plans and merit proposals hold pay data, so owners, administrators,
        // HR and payroll only. Approving a merit proposal is reserved by the policy to owners, administrators and members
        // granted talent_planning.approve.
        'talent_planning' => ['read' => ['owner', 'admin', 'hr', 'payroll'], 'write' => ['owner', 'admin', 'hr', 'payroll']],
        // Returns and warranty (RMA): sales and warehouse staff handle the returns; authorising a return, overriding the
        // return window and deciding a warranty claim are reserved by the policy to owners, administrators and members
        // granted returns.approve.
        'returns' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager', 'warehouse', 'purchasing', 'service_manager', 'technician', 'member']],
        // Collaboration (global search, comments with mentions, shared tags, record timeline): every member reads and writes.
        // Each record type also needs read access to its own module, checked per request by the collaboration registry.
        'collaboration' => ['read' => $anyMember, 'write' => $anyMember],
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
