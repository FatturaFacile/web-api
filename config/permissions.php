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
$financeReaders = [...$finance, 'accountant_readonly', 'external_fiduciary'];
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
        // Bi-temporal time travel and audit engine (Mod 354): finance and auditors read the books at any (valid, known) coordinate and the
        // integrity certificate; finance runs the what-if replays and materializes the checkpoints.
        'time_travel' => ['read' => $financeReaders, 'write' => $finance],
        // Multi-currency engine (Mod 003): every member reads the rates and the active currencies (documents need them); finance
        // enters manual and contract rates, triggers the synchronisation and changes the currency settings.
        'currencies' => [
            'read' => $anyMember,
            'write' => $finance,
            'grants' => ['read' => ['currencies.read', 'currencies.write', 'currencies.*'], 'write' => ['currencies.write', 'currencies.*']],
        ],
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
        'fiduciary_portal' => [
            'read' => [...$financeReaders, 'finance_director'],
            'write' => [...$finance, 'external_fiduciary', 'finance_director'],
            'grants' => [
                'read' => ['fiduciary_portal.*', 'gl.*'],
                'write' => ['fiduciary_portal.*', 'gl.*'],
            ],
        ],
        'procurement' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'purchasing', 'procurement_manager', 'department_manager', 'division_director', 'cfo', 'ceo', 'member']],
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
        // Leave, Permits & Absences Management (Mod 074): every member reads/submits own requests; LeaveManagementPolicy enforces HR/manager approval and admin boundaries.
        'leave_management' => [
            'read' => $anyMember,
            'write' => $anyMember,
            'grants' => [
                'read' => ['leave_management.read', 'leave_management.write', 'leave_management.approve', 'leave_management.*'],
                'write' => ['leave_management.write', 'leave_management.approve', 'leave_management.*'],
            ],
        ],
        // Marketing Automation (Mod 155): promotional outreach is run by sales; everyone with CRM access reads the dashboards.
        'marketing_automation' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'sales', 'sales_manager']],
        // Sales commissions and agent management (Mod 041): commissions are part of someone's pay. Owners, administrators,
        // accountants and sales managers read and maintain conditions; AgentCommissionPolicy reserves the approval and the
        // payment of a statement, the fiscal parameters and the compliance exports to owners, administrators and accountants.
        'agent_commissions' => [
            'read' => ['owner', 'admin', 'accountant', 'accountant_readonly', 'sales_manager'],
            'write' => ['owner', 'admin', 'accountant', 'sales_manager'],
            'grants' => [
                'read' => ['agent_commissions.read', 'agent_commissions.write', 'agent_commissions.settle', 'agent_commissions.*'],
                'write' => ['agent_commissions.write', 'agent_commissions.settle', 'agent_commissions.*'],
            ],
        ],
        // Withholding taxes on commissions, royalties, occasional work and amateur sports (Mod 356): finance and HR read and maintain the
        // registry and the declarations; WithholdingRecipientsPolicy reserves posting, F24 / UniEmens and the annual CU to owners,
        // administrators and accountants.
        'withholding_recipients' => [
            'read' => ['owner', 'admin', 'accountant', 'accountant_readonly', 'hr_manager', 'hr'],
            'write' => ['owner', 'admin', 'accountant', 'hr_manager', 'hr'],
            'grants' => [
                'read' => ['withholding_recipients.read', 'withholding_recipients.write', 'withholding_recipients.settle', 'withholding_recipients.*'],
                'write' => ['withholding_recipients.write', 'withholding_recipients.settle', 'withholding_recipients.*'],
            ],
        ],
        // Dunning and debt collection: read for finance and sales managers, write for owners, administrators and
        // accountants; the legal steps (Betreibung dossier, messa in mora, releasing an exclusion) can also be granted to
        // another member on its own with debt_collection.escalate (DebtCollectionPolicy).
        'debt_collection' => [
            'read' => ['owner', 'admin', 'accountant', 'accountant_readonly', 'sales_manager'],
            'write' => ['owner', 'admin', 'accountant'],
            'grants' => [
                'read' => ['debt_collection.read', 'debt_collection.write', 'debt_collection.escalate', 'debt_collection.*'],
                'write' => ['debt_collection.write', 'debt_collection.escalate', 'debt_collection.*'],
            ],
        ],
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
        // Electronic signatures (FES / FEA / FEQ). Every member may follow and start the signature of a document they can
        // already see; ElectronicSignaturePolicy keeps the provider settings and the policies to owners and administrators.
        'electronic_signature' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager', 'purchasing', 'procurement_manager', 'hr', 'member'],
            'grants' => [
                'read' => ['electronic_signature.read', 'electronic_signature.write', 'electronic_signature.*'],
                'write' => ['electronic_signature.write', 'electronic_signature.*'],
            ],
        ],
        // Intercompany mirror postings, reciprocal reconciliation and the consolidation workflow (Mod 021): finance roles only;
        // the ledgers of every member entity are checked again by ConsolidationAccessGuard.
        'intercompany' => [
            'read' => $financeReaders,
            'write' => $finance,
            'grants' => ['read' => ['intercompany.read', 'intercompany.write', 'intercompany.*', 'gl.*'], 'write' => ['intercompany.write', 'intercompany.*', 'gl.*']],
        ],
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
        // Executive DSS (Mod 093, Issue #214). bi.dss.view reads the cockpit; bi.dss.simulate runs what-if scenarios; bi.dss.export
        // generates the board pack; bi.dss.admin reconciles and rebuilds the data mart. A grant of a higher ability opens the lower reads.
        'bi_dss' => [
            'read' => [...$financeReaders, 'sales_manager', 'procurement_manager'],
            'write' => $finance,
            'grants' => ['read' => ['bi.dss.view', 'bi.dss.simulate', 'bi.dss.export', 'bi.dss.admin', 'bi.dss.*'], 'write' => ['bi.dss.admin', 'bi.dss.*']],
        ],
        'bi_dss_simulate' => ['read' => $finance, 'write' => $finance, 'grants' => ['write' => ['bi.dss.simulate', 'bi.dss.*']]],
        'bi_dss_export' => ['read' => $finance, 'write' => $finance, 'grants' => ['write' => ['bi.dss.export', 'bi.dss.*']]],
        'bi_dss_admin' => ['read' => $managers, 'write' => $managers, 'grants' => ['read' => ['bi.dss.admin', 'bi.dss.*'], 'write' => ['bi.dss.admin', 'bi.dss.*']]],
        'ocr_capture' => ['read' => $anyMember, 'write' => $anyMember],
        // Executive KPI dashboards (Mod 090). kpi_dashboards: any member opens the cockpits their role allows and saves their own
        // layouts (KpiAccess narrows each KPI by role, legal entity, cost center and compensation grant); kpi_dashboards_admin:
        // finance and managers configure threshold alerts and refresh the analytical cubes.
        'kpi_dashboards' => ['read' => $anyMember, 'write' => $anyMember],
        // Italian payroll pre-processing (Mod 077): attendance validation, consultant export, payslip import, accounting import and
        // SEPA salaries are pay data of every employee (owners, administrators or payroll.* on the membership, like payroll).
        // italian_payroll_self: employees open their own payslips and CU; the handler matches the signed-in user to the employee.
        'italian_payroll' => [
            'read' => $managers,
            'write' => $managers,
            'grants' => ['read' => ['payroll.read', 'payroll.write', 'payroll.*'], 'write' => ['payroll.write', 'payroll.*']],
        ],
        'italian_payroll_self' => ['read' => $anyMember, 'write' => $anyMember],
        'kpi_dashboards_admin' => [
            'read' => $financeReaders,
            'write' => $finance,
            'grants' => ['read' => ['kpi.*'], 'write' => ['kpi.*']],
        ],
        // Interactive quotes and proposals (Mod 036). cpq_quotes: the sales team builds, issues, revises and converts quotes;
        // cpq_quotes_admin: managers override the margin guardrail (approve-and-issue).
        'cpq_quotes' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager']],
        'cpq_quotes_admin' => ['read' => ['owner', 'admin', 'sales_manager'], 'write' => ['owner', 'admin', 'sales_manager']],
        // Technical-commercial product configurator (Mod 230): every member reads and configures with live pricing; sales staff confirm
        // configurations and print proposals. Models, rules, mappings and formulas are reserved by the policy to owners, administrators,
        // sales managers and members granted cpq_configurator.manage; industrial cost and margin to cpq_configurator.costs.
        'cpq_configurator' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager']],
        // The definition of models, rules, mappings and formulas of the configurator: the policy asks for owners, administrators, sales managers and cpq_configurator.manage.
        'cpq_configurator_admin' => ['read' => ['owner', 'admin', 'sales_manager'], 'write' => ['owner', 'admin', 'sales_manager']],
        // Data residency, sovereignty and BYOK/HYOK (Mod 381). sovereignty_policy (sovereignty.policy.read / .manage): owners and
        // administrators (DPO, CISO) read the posture and enforce the residency policy; sovereignty_byok (sovereignty.byok.manage):
        // configure and rotate the customer-managed key and read the KMS log; sovereignty_killswitch (sovereignty.byok.killswitch):
        // the emergency suspension and crypto-shredding, owners only (the handler also asks for a step-up MFA code);
        // sovereignty_attestation (sovereignty.attestation.export): issue and download the signed certificate.
        'sovereignty_policy' => ['read' => $managers, 'write' => $managers, 'grants' => ['read' => ['sovereignty.policy.read', 'sovereignty.*'], 'write' => ['sovereignty.policy.manage', 'sovereignty.*']]],
        'sovereignty_byok' => ['read' => $managers, 'write' => $managers, 'grants' => ['read' => ['sovereignty.byok.manage', 'sovereignty.*'], 'write' => ['sovereignty.byok.manage', 'sovereignty.*']]],
        'sovereignty_killswitch' => ['read' => ['owner'], 'write' => ['owner'], 'grants' => ['read' => ['sovereignty.byok.killswitch'], 'write' => ['sovereignty.byok.killswitch']]],
        'sovereignty_attestation' => ['read' => $managers, 'write' => $managers, 'grants' => ['read' => ['sovereignty.attestation.export', 'sovereignty.*'], 'write' => ['sovereignty.attestation.export', 'sovereignty.*']]],
        // Swiss QR-bill, ISO 20022 and country fiscal documents (Issue #83): finance issues and reads them.
        'swiss_fiscal' => ['read' => $financeReaders, 'write' => $finance],
        // Workforce shift planning (Mod 073). shift_planning: HR, production and managers plan, publish and lock rosters, see the
        // labour cost and decide swaps; shift_planning_self: every employee reads the own agenda, claims open shifts and swaps.
        'shift_planning' => [
            'read' => ['owner', 'admin', 'hr', 'production'],
            'write' => ['owner', 'admin', 'hr', 'production'],
            'grants' => ['read' => ['shift_planning.*'], 'write' => ['shift_planning.*']],
        ],
        // Artisanal gelato, chocolaterie and cold pastry (Mod 398): laboratory staff balance recipes, record lots and HACCP logs and
        // publish the showcase; any member reads recipes, the showcase and the allergen book.
        'artisanal_gelato' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'production', 'technician', 'member']],
        'shift_planning_self' => ['read' => $anyMember, 'write' => $anyMember],
        // Resource planning and capacity saturation (Mod 079): project managers, HR and production book resources, convert tentative
        // bookings, rebalance overloads and manage the skill matrix; every member reads capacity, the skill matrix and utilization.
        'resource_planning' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'hr', 'production', 'project_manager'],
            'grants' => ['read' => ['resource_planning.*'], 'write' => ['resource_planning.*']],
        ],
        // Italian VAT compliance (Mod 029): registers, plafond, LIPE, annual VAT and CU belong to the accounting roles; the read-only
        // accountant reads them.
        'italian_vat' => ['read' => $financeReaders, 'write' => $finance, 'grants' => ['read' => ['italian_vat.*'], 'write' => ['italian_vat.*']]],
        // Accounts payable (Mod 015): the accounting roles keep the supplier ledger and prepare the payment runs; the read-only accountant
        // reads them. The approval of a payment run (CFO) is a separate module: owners and administrators, or a member granted
        // accounts_payable_approval.write.
        'accounts_payable' => ['read' => $financeReaders, 'write' => $finance, 'grants' => ['read' => ['accounts_payable.*'], 'write' => ['accounts_payable.*']]],
        // Liquidity planning (Mod 017): rolling cash forecast, DSCR / capital / PFN crisis indicators, what-if scenarios and alerts.
        'liquidity_planning' => ['read' => $financeReaders, 'write' => $finance, 'grants' => ['read' => ['liquidity_planning.*'], 'write' => ['liquidity_planning.*']]],
        // Corporate crisis monitoring (Mod 357): rolling DSCR, CCII / Art. 2086 c.c. early warning, Art. 725 CO guardrails, Rangrücktritt registry, stress test.
        'crisis_monitoring' => ['read' => $financeReaders, 'write' => $finance, 'grants' => ['read' => ['crisis_monitoring.*'], 'write' => ['crisis_monitoring.*']]],
        'accounts_payable_approval' => ['read' => $financeReaders, 'write' => $managers],
        // Swiss VAT compliance (Mod 024): Effective & Saldosteuersatz returns, Bezugsteuer, input tax adjustments, and SuisseTax XML.
        'swiss_vat' => ['read' => $financeReaders, 'write' => $finance, 'grants' => ['read' => ['swiss_vat.*'], 'write' => ['swiss_vat.*']]],
        'ocr_settings' => ['read' => $managers, 'write' => $managers],
        // Workplace health, safety and training (Mod 099). hse: the operational roles read the compliance matrix, the clearance verdict
        // and the physician judgments (outcome and prescriptions); the HSE team (hr, hse, admins) writes certificates, PPE and incidents.
        // hse_medical: who records physician judgments. hse_clinical: clinical notes belong to the occupational physician alone,
        // so owners and admins are deliberately absent (GDPR Art. 9 / nLPD).
        'hse' => [
            'read' => ['owner', 'admin', 'hr', 'hse', 'production', 'technician', 'service_manager', 'project_manager', 'accountant', 'member'],
            'write' => ['owner', 'admin', 'hr', 'hse'],
        ],
        // MRP I & II (Mod 063). mrp_planning: every member reads runs, proposals, pegging and the Gantt; production planners and admins run MRP,
        // level the capacity and move Gantt blocks. mrp_release: firming, releasing and dismissing proposals creates purchase requisitions and
        // production orders, so only the roles that commit materials and capacity do it.
        'mrp_planning' => ['read' => $anyMember, 'write' => ['owner', 'admin', 'production', 'technician', 'purchasing', 'procurement_manager']],
        'mrp_release' => ['read' => ['owner', 'admin', 'production', 'purchasing', 'procurement_manager'], 'write' => ['owner', 'admin', 'production', 'purchasing', 'procurement_manager']],
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
        // Expense reports (Mod 075): every member files their own; ExpenseReportPolicy reserves approval and payout to managers and finance.
        'expense_management' => ['read' => $anyMember, 'write' => $anyMember],
        // Tender management (Mod 169): bids, vault documents and offers are finance work; TenderManagementPolicy keeps the award conversion to owners, administrators and accountants.
        'tender_management' => ['read' => $financeReaders, 'write' => $finance],
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
        // to owners, administrators and members granted budgeting.approve. The spend lock (Mod 019) adds budgeting.control.manage
        // (spend rules, thresholds, actual volumes: owners, administrators and accountants have it) and budgeting.override.approve
        // (the CFO decision on an override request: owners and administrators have it).
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
        // Industrial and analytical cost accounting (Mod 018): finance, sales management and production read; owners,
        // administrators and accountants keep centers, rules, drivers and manual analytical postings. Running, consolidating
        // and reversing apportionment cycles is reserved by the policy to analytical.apportionment.run.
        'analytical_accounting' => [
            'read' => [...$financeReaders, 'sales_manager', 'production', 'procurement_manager'],
            'write' => [...$finance],
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
        // GDO trade terms (Mod 231): framework contracts with retail chains, tiered year-end rebates, trade promotions, monthly
        // accruals and settlements. Finance and sales roles read; owners, administrators, accountants and sales managers write.
        // GdoTradeTermsPolicy reserves running and reversing the accrual and settling to finance (or gdo_trade_terms.settle).
        'gdo_trade_terms' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager'],
            'write' => [...$finance, 'sales_manager'],
            'grants' => [
                'read' => ['gdo_trade_terms.read', 'gdo_trade_terms.write', 'gdo_trade_terms.settle', 'gdo_trade_terms.*'],
                'write' => ['gdo_trade_terms.write', 'gdo_trade_terms.settle', 'gdo_trade_terms.*'],
            ],
        ],
        // Industrial laundries and linen / workwear rental (Mod 177): tunnel gate scans, par stock contracts, PPE wash limits, RABC
        // batches and the monthly bill. Finance, sales and warehouse roles read; owners, administrators, accountants, sales
        // managers and the warehouse and production staff of the laundry write. IndustrialLaundryPolicy reserves consolidating the
        // monthly bill and drafting its invoice to finance (or industrial_laundry.bill).
        'industrial_laundry' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager', 'warehouse', 'production'],
            'write' => [...$finance, 'sales_manager', 'warehouse', 'production'],
            'grants' => [
                'read' => ['industrial_laundry.read', 'industrial_laundry.write', 'industrial_laundry.bill', 'industrial_laundry.*'],
                'write' => ['industrial_laundry.write', 'industrial_laundry.bill', 'industrial_laundry.*'],
            ],
        ],
        // Statutory financial statements (Mod 167): XBRL civil-code and Swiss CO / Swiss GAAP FER dossier, notes, crisis indicators, PDF/A
        // deposit package. Finance roles read; owners, administrators and accountants write. StatutoryFinancialsPolicy reserves locking,
        // reopening and filing a dossier to finance (or statutory_financials.file).
        'statutory_financials' => [
            'read' => [...$financeReaders],
            'write' => [...$finance],
            'grants' => [
                'read' => ['statutory_financials.read', 'statutory_financials.write', 'statutory_financials.file', 'statutory_financials.*'],
                'write' => ['statutory_financials.write', 'statutory_financials.file', 'statutory_financials.*'],
            ],
        ],
        // Passenger transport (Mod 176): bookings, dispatch, fleet, driver compliance, tachograph archive, NCC service sheets, concierge
        // and school bus contracts. Finance, dispatchers and operations managers read and write; PassengerTransportPolicy reserves
        // invoicing to finance (or passenger_transport.bill).
        'passenger_transport' => [
            'read' => [...$financeReaders, 'dispatcher', 'operations_manager', 'sales', 'sales_manager'],
            'write' => [...$finance, 'dispatcher', 'operations_manager'],
            'grants' => [
                'read' => ['passenger_transport.read', 'passenger_transport.write', 'passenger_transport.bill', 'passenger_transport.*'],
                'write' => ['passenger_transport.write', 'passenger_transport.bill', 'passenger_transport.*'],
            ],
        ],
        // Customer satisfaction surveys (Mod 245): NPS / CSAT / CES templates, dispatch monitor, closed-loop detractor recovery and the
        // ISO 9001 § 9.1.2 analytics. Sales, service and quality roles work the recovery queue; finance and managers read the analytics.
        'customer_satisfaction' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager', 'operations_manager', 'support_manager', 'dispatcher', 'technician'],
            'write' => [...$finance, 'sales_manager', 'operations_manager', 'support_manager'],
            'grants' => [
                'read' => ['customer_satisfaction.read', 'customer_satisfaction.write', 'customer_satisfaction.*'],
                'write' => ['customer_satisfaction.write', 'customer_satisfaction.*'],
            ],
        ],
        // Distribution, multi-warehouse and transfers - DRP (Issue #271): warehouse, purchasing and operations roles plan the network;
        // every member reads. Consignment settlements and transfers follow the inventory write roles.
        'distribution' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'warehouse', 'purchasing', 'operations_manager', 'sales_manager', 'technician', 'member'],
            'grants' => [
                'read' => ['distribution.read', 'distribution.write', 'distribution.*'],
                'write' => ['distribution.write', 'distribution.*'],
            ],
        ],
        // Swiss eBill (Mod 025): direct SIX Paynet / PostFinance transmission of issued invoices, the participant directory of the customers, the
        // lifecycle monitor and the settlement via camt.054. Finance configures and sends; sales roles read the delivery state of their invoices.
        'swiss_ebill' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager'],
            'write' => [...$finance, 'sales_manager'],
            'grants' => [
                'read' => ['swiss_ebill.read', 'swiss_ebill.write', 'swiss_ebill.*'],
                'write' => ['swiss_ebill.write', 'swiss_ebill.*'],
            ],
        ],
        // Subcontracting (Mod 065): conto lavoro passivo/attivo, stock at subcontractors and the Art. 53 DPR 633/72 register. Production,
        // procurement and operations plan, dispatch and receive; finance reads and exports the register. SubcontractingPolicy
        // adds the fine-grained subcontracting.* permissions (view, create, dispatch, receive, ledger.export).
        // Foreign VAT refund (Mod 227): ledger of the foreign VAT, claims to the EU portal / ESTV / Pescara, mandates of the fiscal representatives
        // and the accounting of the refund. Finance roles work on it; purchasing and operations managers read the split of their documents.
        'foreign_vat_refund' => [
            'read' => [...$financeReaders, 'procurement_manager', 'operations_manager'],
            'write' => [...$finance],
            'grants' => [
                'read' => ['foreign_vat_refund.read', 'foreign_vat_refund.write', 'foreign_vat_refund.*'],
                'write' => ['foreign_vat_refund.write', 'foreign_vat_refund.*'],
            ],
        ],
        // Green care (Mod 126): sites, seasonal contracts, crews, routes, phytosanitary diary and green waste. Crews close their own stops and
        // write the diary; finance and operations manage contracts and billing.
        'green_care' => [
            'read' => [...$financeReaders, 'operations_manager', 'service_manager', 'technician', 'project_manager', 'member'],
            'write' => [...$finance, 'operations_manager', 'service_manager', 'technician', 'member'],
            'grants' => [
                'read' => ['green_care.read', 'green_care.write', 'green_care.*'],
                'write' => ['green_care.write', 'green_care.*'],
            ],
        ],
        'subcontracting' => [
            'read' => [...$financeReaders, 'production', 'procurement_manager', 'operations_manager', 'sales_manager', 'technician'],
            'write' => [...$finance, 'production', 'procurement_manager', 'operations_manager'],
            'grants' => [
                'read' => ['subcontracting.view', 'subcontracting.read', 'subcontracting.write', 'subcontracting.create', 'subcontracting.dispatch', 'subcontracting.receive', 'subcontracting.ledger.export', 'subcontracting.*'],
                'write' => ['subcontracting.write', 'subcontracting.create', 'subcontracting.dispatch', 'subcontracting.receive', 'subcontracting.*'],
            ],
        ],
        // Down-payment chain (Mod 203): advance invoices (TD02), pro-forma requests, earnest money and security deposits, the final
        // settlement with the multi-rate deduction and the advance credit notes. Sales plan and emit; finance resolves deposits, settles
        // and credits. DownPaymentPolicy adds the fine-grained down_payments.* permissions.
        'down_payments' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager'],
            'write' => [...$finance, 'sales_manager'],
            'grants' => [
                'read' => ['down_payments.view', 'down_payments.read', 'down_payments.write', 'down_payments.create', 'down_payments.emit', 'down_payments.deposit.resolve', 'down_payments.settle', 'down_payments.credit_note', 'down_payments.*'],
                'write' => ['down_payments.write', 'down_payments.create', 'down_payments.emit', 'down_payments.deposit.resolve', 'down_payments.settle', 'down_payments.credit_note', 'down_payments.*'],
            ],
        ],
        // Franchise network governance (Mod 244): the franchise director (owner, administrator, franchise_director) governs the network,
        // the area manager audits the stores assigned to them, the franchisee owner and manager use the portal of their own store.
        // FranchiseNetworkPolicy reserves every action to its role.
        'franchise_network' => [
            'read' => $anyMember,
            'write' => [...$finance, 'franchise_director', 'area_manager', 'franchisee_owner', 'franchisee_manager'],
            'grants' => [
                'read' => ['franchise_network.read', 'franchise_network.write', 'franchise_network.manage', 'franchise_network.audit', 'franchise_network.portal', 'franchise_network.*'],
                'write' => ['franchise_network.write', 'franchise_network.manage', 'franchise_network.audit', 'franchise_network.portal', 'franchise_network.*'],
            ],
        ],
        // Product catalog (Mod 052): every member reads; owners, administrators, accountants, warehouse, purchasing and sales roles
        // maintain products, variant matrices, kits, units of measure and the CH / IT classification, and import / export the catalog.
        'product_catalog' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'warehouse', 'purchasing', 'sales', 'sales_manager', 'member'],
            'grants' => [
                'read' => ['product_catalog.read', 'product_catalog.write', 'product_catalog.*'],
                'write' => ['product_catalog.write', 'product_catalog.*'],
            ],
        ],
        // Corporate conventions, partner employee discounts and welfare cards (Mod 358): sales and finance roles read and write the
        // conventions, the till and the welfare vouchers; ConventionPolicy reserves the B2B invoice of the employer quota and the
        // settlement of welfare vouchers to finance (or corporate_conventions.bill).
        'corporate_conventions' => [
            'read' => [...$financeReaders, 'sales', 'sales_manager'],
            'write' => [...$finance, 'sales', 'sales_manager'],
            'grants' => [
                'read' => ['corporate_conventions.read', 'corporate_conventions.write', 'corporate_conventions.bill', 'corporate_conventions.*'],
                'write' => ['corporate_conventions.write', 'corporate_conventions.bill', 'corporate_conventions.*'],
            ],
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
        // Van Selling & Offline Field Sales (Mod 233, Issue #479): mobile van warehouses, global load DDT, field routes,
        // GS1-128 variable-weight sales, dedicated fiscal sectionals, thermal printing, offline delta sync & EOD settlement.
        'van_sales_and_field_force' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager', 'warehouse', 'dispatcher', 'operations_manager', 'member'],
            'grants' => [
                'read' => ['van_sales.view', 'van_sales.manage', 'van_sales.settle', 'van_sales.credit_override', 'van_sales.*'],
                'write' => ['van_sales.manage', 'van_sales.settle', 'van_sales.credit_override', 'van_sales.*'],
            ],
        ],
        // Epic [Dominio 04] SD#139 — Sales Bounded Context (Mod 033-042).
        'sales_bounded_context' => [
            'read' => $anyMember,
            'write' => ['owner', 'admin', 'accountant', 'sales', 'sales_manager', 'member'],
            'grants' => [
                'read' => ['sales_bc.view', 'sales_bc.manage', 'sales_bc.approve_margin', 'sales_bc.*'],
                'write' => ['sales_bc.manage', 'sales_bc.approve_margin', 'sales_bc.*'],
            ],
        ],
        // Guided Data Migration & Import/Export Wizard (Mod 104, Issue #253).
        'data_migration' => [
            'read' => $financeReaders,
            'write' => $finance,
            'grants' => [
                'read' => ['data_migration.read', 'data_migration.view', 'data_migration.write', 'data_migration.manage', 'data_migration.execute', 'data_migration.rollback', 'data_migration.*'],
                'write' => ['data_migration.write', 'data_migration.manage', 'data_migration.execute', 'data_migration.rollback', 'data_migration.*'],
            ],
        ],

        // Italian Esterometro & Reverse Charge Self-Invoicing (Mod 027, Issue #287).
        'esterometro' => [
            'read' => $financeReaders,
            'write' => $finance,
            'grants' => [
                'read' => ['esterometro.read', 'esterometro.view', 'esterometro.write', 'esterometro.manage', 'esterometro.send', 'esterometro.*'],
                'write' => ['esterometro.write', 'esterometro.manage', 'esterometro.send', 'esterometro.*'],
            ],
        ],
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
