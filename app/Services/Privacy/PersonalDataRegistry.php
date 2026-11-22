<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Access\TenantInvitation;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\BankReconciliationRule;
use App\Models\BankStatementLine;
use App\Models\ConstructionSite;
use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\ContactPerson;
use App\Models\DeliveryNote;
use App\Models\EmployeeProfile;
use App\Models\Invoice;
use App\Models\InvoiceQrBillData;
use App\Models\PortalActivityLog;
use App\Models\Privacy\ConsentLedgerEntry;
use App\Models\Privacy\PrivacySensitiveAccessLog;
use App\Models\Privacy\PrivacySubjectRequestDsar;
use App\Models\PurchaseOrder;
use App\Models\QuoteSignatureRequest;
use App\Models\SalesOrder;
use App\Models\ServiceReport;
use App\Models\ServiceReportSignature;
use App\Models\User;
use App\Models\VatValidationLog;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use InvalidArgumentException;
use Modules\Associations\Models\Donor;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\PrivacySubject;
use Modules\Associations\Models\Sponsor;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\ReferencePerson;
use Modules\Communication\Models\CampaignRecipient;
use Modules\Communication\Models\CommunicationConversation;
use Modules\Communication\Models\ConversationMessage;
use Modules\Contracts\Models\ContractParty;
use Modules\Contracts\Models\ContractSigner;
use Modules\CRM\Models\Consent;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Partner;
use Modules\DocumentTrust\Models\PreservationAccessLog;
use Modules\Events\Models\EventRegistration;
use Modules\Fleet\Models\FleetVehicle;
use Modules\Fleet\Models\FleetVehicleAssignment;
use Modules\GeneralLedger\Models\Us1099Recipient;
use Modules\HealthExpenses\Models\HealthExpense;
use Modules\Hospitality\Models\Reservation;
use Modules\Hospitality\Models\Stay;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\CandidateEmail;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\ExitInterview;
use Modules\HumanResources\Models\InterviewNote;
use Modules\HumanResources\Models\JobApplication;
use Modules\Inventory\Models\InventoryAuditEntry;
use Modules\Inventory\Models\SafetyDataSheetDelivery;
use Modules\Inventory\Models\WeighbridgeVehicle;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesRepresentative;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\Payslip;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContact;
use Modules\Procurement\Models\SupplierQuote;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\SupportMailboxMessage;
use Modules\ServiceManagement\Models\TicketMessage;
use Modules\SupplierPortal\Models\BankChangeRequest;
use Modules\SupplierPortal\Models\SupplierOnboarding;
use Modules\TalentLearning\Models\EngagementAnswer;
use Modules\TalentLearning\Models\EngagementResponse;
use Modules\Treasury\Models\OpenBankingConsent;
use Modules\Treasury\Models\OpenBankingPayment;
use Modules\Treasury\Models\PagoPaPaymentNotice;
use Modules\Treasury\Models\PaymentApproval;
use Modules\Treasury\Models\PaymentLine;
use Modules\Treasury\Models\RibaReceipt;
use Modules\Visitors\Models\Visitor;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowAuditEntry;
use Modules\Workshop\Models\VehicleStockUnit;

/**
 * The single place that declares which tables of the tenant hold personal data (GDPR art. 30 and 5(2), nLPD art. 12).
 *
 * A new model with personal columns MUST be declared here, either as an entry (the personal columns, the kind of
 * subject and the retention rule) or as an exemption with the reason. tests/Feature/Privacy/PersonalDataRegistryArchTest
 * reads every model of the application and fails when one with personal-looking columns is neither.
 *
 * The retention classes follow the rules already applied by GlobalPrivacyComplianceService: accounting and payroll
 * vouchers 10 years (Swiss CO art. 958f), working time records 5 years (ArG art. 46, ArGV 1 art. 73). For Italian
 * tenants the equivalent rule is the 10 years of art. 2220 of the Codice civile. These periods are NOT verified by this
 * code against the official texts and must be confirmed by the data protection officer or counsel; the classification
 * of each table below is the conservative choice (when in doubt a record is kept, not erased).
 */
final class PersonalDataRegistry
{
    /** @var array<string, PersonalDataEntry>|null */
    private static ?array $entries = null;

    /**
     * Subject types a data subject request can be raised for.
     *
     * @return list<string>
     */
    public static function subjectTypes(): array
    {
        return ['contact', 'employee', 'lead', 'user', 'member', 'child', 'visitor', 'applicant'];
    }

    /**
     * @return array<string, PersonalDataEntry>
     */
    public function entries(): array
    {
        return self::$entries ??= $this->build();
    }

    public function find(string $key): ?PersonalDataEntry
    {
        return $this->entries()[$key] ?? null;
    }

    public function findOrFail(string $key): PersonalDataEntry
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown personal data registry entry [{$key}].");
    }

    /**
     * Entries whose rows can be tied to a subject of the given type.
     *
     * @return list<PersonalDataEntry>
     */
    public function entriesForSubject(string $subjectType): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (PersonalDataEntry $entry): bool => isset($entry->subjectLinks[$subjectType]),
        ));
    }

    /**
     * Tables that reference a subject without holding personal columns of their own (orders, tickets, leave requests...).
     * They belong to the export and to the map of the data, and are kept when the subject is erased.
     *
     * @return array<string, array{links: array<string, string>, retention: PersonalDataRetention, note: string, hasTenantColumn: bool}>
     */
    public function linkedRecords(): array
    {
        $fiscal = PersonalDataRetention::FiscalRecord;
        $employment = PersonalDataRetention::StatutoryEmployment;
        $operational = PersonalDataRetention::Erasable;
        $evidenceClass = PersonalDataRetention::Evidence;
        $commercial = 'Commercial record kept with the accounting documents.';
        $hr = 'Employment record, kept while the statutory period of the employer runs.';

        $link = static fn (string $subject, string $column, PersonalDataRetention $retention, string $note, bool $tenant = true): array => [
            'links' => [$subject => $column], 'retention' => $retention, 'note' => $note, 'hasTenantColumn' => $tenant,
        ];

        return [
            'quotes' => $link('contact', 'contact_id', $fiscal, $commercial),
            'tenant_user' => $link('user', 'user_id', $evidenceClass, 'Membership of the user in the company (role and permissions): removed by a user erasure.'),
            'sso_identities' => $link('user', 'user_id', $operational, 'Identity of the user at the identity provider of the company: removed by a user erasure.'),
            'assoc_receipts' => $link('member', 'member_id', $fiscal, 'Fee receipt: fiscal record of the association.'),
            'assoc_fee_payments' => $link('member', 'member_id', $fiscal, 'Fee payment: fiscal record of the association.'),
            'assoc_memberships' => $link('member', 'member_id', $evidenceClass, 'Members book entry, kept by the statutes and by law.'),
            'assoc_consents' => $link('member', 'member_id', $evidenceClass, 'Consent history: proof of consent (art. 7(1) GDPR).'),
            'cc_monthly_bills' => $link('child', 'child_id', $fiscal, 'Childcare bill: accounting record.'),
            'cc_child_fees' => $link('child', 'child_id', $fiscal, 'Childcare fee: accounting record.'),
            'cc_attendances' => $link('child', 'child_id', $fiscal, 'Attendance register used for billing and subsidies; erased by the childcare module once no longer needed (not automated).'),
            'visitor_visits' => $link('visitor', 'visitor_id', $operational, 'Visit log: holds no personal data once the visitor is anonymised; purged by the visitors purge job.'),
            'hr_application_stage_events' => $link('applicant', 'job_application_id', $operational, 'Stage history of the application: no personal data of the applicant beyond the link.'),
            'sales_returns' => $link('contact', 'contact_id', $fiscal, $commercial),
            'returns_rmas' => $link('contact', 'contact_id', $fiscal, $commercial),
            'returns_warranties' => $link('contact', 'contact_id', $operational, 'Warranty of a sold unit: erased with the contact when no statutory record needs it.'),
            'subscriptions' => $link('contact', 'contact_id', $fiscal, $commercial),
            'pos_sales' => $link('contact', 'contact_id', $fiscal, $commercial),
            'projects' => $link('contact', 'contact_id', $fiscal, $commercial),
            'esm_tickets' => $link('contact', 'contact_id', $operational, 'Support history, erased with the contact when no statutory record needs it.'),
            'loyalty_cards' => $link('contact', 'contact_id', $operational, 'Loyalty programme data.'),
            'crm_opportunities' => [
                'links' => ['contact' => 'contact_id', 'lead' => 'lead_id'], 'retention' => $operational, 'note' => 'Sales pipeline data.', 'hasTenantColumn' => true,
            ],
            'hr_employment_contracts' => $link('employee', 'employee_id', $employment, $hr),
            'hr_leave_requests' => $link('employee', 'employee_id', $employment, $hr),
            'hr_timesheets' => $link('employee', 'employee_id', $employment, $hr),
            'hr_certifications' => $link('employee', 'employee_id', $employment, $hr),
            'hr_training_records' => $link('employee', 'employee_id', $employment, $hr),
            'hr_performance_reviews' => $link('employee', 'employee_id', $employment, $hr),
            'hr_employee_documents' => $link('employee', 'employee_id', $employment, $hr),
            'fleet_fuel_entries' => $link('employee', 'employee_id', $operational, 'Fuel and charging entry of a company vehicle: no personal data beyond the link to the driver.'),
            'fleet_incidents' => $link('employee', 'employee_id', $employment, 'Fine, accident or damage attributed to the driver: kept while the employer may need it for the recovery of the amount, to be confirmed.'),
            'hr_attendance_records' => $link('employee', 'employee_id', $employment, 'Working time record: 5 years (ArG art. 46, ArGV 1 art. 73), to be confirmed.'),
            'payroll_salary_certificates' => $link('employee', 'employee_id', $fiscal, 'Salary certificate: payroll voucher, 10 years (CO art. 958f), to be confirmed.'),
            'payroll_inputs' => $link('employee', 'employee_id', $fiscal, 'Payroll input: payroll voucher.'),
            'payroll_absences' => $link('employee', 'employee_id', $fiscal, 'Payroll absence: payroll voucher.'),
        ];
    }

    /**
     * Tables with personal-looking columns that hold no personal data of natural persons, with the reason.
     *
     * @return array<string, string>
     */
    public function exemptions(): array
    {
        return [
            'tenants' => 'Data of the company that uses the system (its own tax code, IBAN and addresses), not of data subjects.',
            'bank_accounts' => 'Own bank accounts of the company.',
            'bank_statements' => 'Own bank account IBAN of the company (the counterparties are in bank_statement_lines).',
            'bank_branches' => 'Reference data of banks.',
            'warehouses' => 'Premises of the company.',
            'eam_asset_locations' => 'Premises of the company.',
            'countries' => 'Reference data (telephone prefix).',
            'country_translations' => 'Reference data (name of the nationality).',
            'payment_methods' => 'Reference data (flag requires_iban).',
            'invoice_items' => 'Reference to a tax code, no personal data.',
            'quote_items' => 'Reference to a tax code, no personal data.',
            'sales_order_items' => 'Reference to a tax code, no personal data.',
            'payroll_wage_types' => 'Configuration (flag subject_to_ahv).',
            'payroll_swiss_settings' => 'Social insurance rates and member number of the employer.',
            'vat_tax_settings' => 'VAT declarant of the company.',
            'esm_support_mailboxes' => 'Mailbox address of the company.',
            'customs_settings' => 'Address of the customs declarant (the company).',
            'portal_settings' => 'White-label settings of the company portal (support email of the company), not of data subjects.',
            'ct_price_lists' => 'Reference to a licence in a price list (not a person).',
            'health_expense_settings' => 'Tax code of the owner of the company.',
            'sso_providers' => 'Configuration of the identity provider of the company: the allowed email domains are company data, not data of data subjects.',
            'saml_providers' => 'Configuration of the identity provider of the company: the allowed email domains and the attribute names are company data, not data of data subjects.',
            'companies' => 'Legal entities of the corporate group (code, legal name, tax code): companies, not data subjects.',
            'company_sites' => 'Premises of the companies of the group.',
            'fpa_documents' => 'Electronic invoice envelopes: the recipient PEC is a business mailbox of the customer company, personal data of the parties stays in the contacts and the invoice.',
            'sdi_configurations' => 'Transmission settings of the company (its own fiscal code and PEC), not data of data subjects.',
        ];
    }

    /**
     * @return array<string, PersonalDataEntry>
     */
    private function build(): array
    {
        $erasable = PersonalDataRetention::Erasable;
        $fiscal = PersonalDataRetention::FiscalRecord;
        $employment = PersonalDataRetention::StatutoryEmployment;
        $evidence = PersonalDataRetention::Evidence;

        $commercialNote = 'Accounting and commercial record: 10 years (Swiss CO art. 958f; Italian c.c. art. 2220), to be confirmed. Never deleted: pseudonymised only through the contact.';
        $evidenceNote = 'Evidence or audit trail: kept to prove accountability, never rewritten.';
        $operationalNote = 'Operational data: erased on request or by retention policy.';
        $manualNote = 'Operational data handled by its own module: erased there, not by the automated request workflow.';

        $list = [
            new PersonalDataEntry('contacts', [Contact::class], 'Customers and suppliers (contacts)', 'identity', ['company_name', 'first_name', 'last_name', 'email', 'phone', 'pec', 'vat_number', 'fiscal_code', 'uid_number', 'iban', 'qr_iban', 'iban_bidx', 'qr_iban_bidx'], $erasable, 'Contact details erased on request; identity kept while accounting records of the contact are within the statutory period (CO art. 958f), to be confirmed.', ['contact' => 'id']),
            new PersonalDataEntry('contact_addresses', [ContactAddress::class], 'Addresses of contacts', 'contact', ['street', 'house_number', 'postal_code', 'city'], $erasable, 'Erased with the contact unless the contact is on hold for accounting records.', ['contact' => 'contact_id'], null, false, ['column' => 'contact_id', 'table' => 'contacts', 'parentColumn' => 'id']),
            new PersonalDataEntry('invoices', [Invoice::class, SalesInvoice::class], 'Invoices', 'financial', ['bank_iban'], $fiscal, $commercialNote, ['contact' => 'contact_id']),
            new PersonalDataEntry('invoice_qr_bill_data', [InvoiceQrBillData::class], 'QR bill data of invoices', 'financial', ['qr_iban'], $fiscal, $commercialNote, [], null, false, ['column' => 'invoice_id', 'table' => 'invoices', 'parentColumn' => 'id']),
            new PersonalDataEntry('sales_orders', [SalesOrder::class, \Modules\Invoicing\Models\SalesOrder::class], 'Sales orders', 'contact', ['billing_address', 'shipping_address'], $fiscal, $commercialNote, ['contact' => 'contact_id']),
            new PersonalDataEntry('delivery_notes', [DeliveryNote::class, SalesDeliveryNote::class], 'Delivery notes', 'contact', ['shipping_address', 'sender_address', 'carrier_license_plate'], $fiscal, $commercialNote, ['contact' => 'contact_id']),
            new PersonalDataEntry('purchase_orders', [PurchaseOrder::class, \Modules\Procurement\Models\PurchaseOrder::class], 'Purchase orders', 'contact', ['shipping_address'], $fiscal, $commercialNote),
            new PersonalDataEntry('service_reports', [ServiceReport::class], 'Service reports', 'contact', ['site_address'], $fiscal, $commercialNote, ['contact' => 'contact_id']),
            new PersonalDataEntry('service_report_signatures', [ServiceReportSignature::class], 'Signatures of service reports', 'identity', ['signer_email', 'ip_address'], $evidence, $evidenceNote),
            new PersonalDataEntry('sp_onboardings', [SupplierOnboarding::class], 'Supplier onboarding', 'identity', ['invitation_email'], $fiscal, $commercialNote),
            new PersonalDataEntry('sp_bank_change_requests', [BankChangeRequest::class], 'Supplier bank detail change requests', 'financial', ['account_holder', 'iban', 'previous_iban'], $fiscal, $commercialNote),
            new PersonalDataEntry('pagopa_payment_notices', [PagoPaPaymentNotice::class], 'pagoPA payment notices', 'financial', ['debtor_name', 'debtor_fiscal_code', 'creditor_fiscal_code'], $fiscal, $commercialNote),
            new PersonalDataEntry('bank_statement_lines', [BankStatementLine::class, \Modules\Treasury\Models\BankStatementLine::class], 'Bank statement lines', 'financial', ['counterpart_iban', 'counterparty_iban'], $fiscal, $commercialNote),
            new PersonalDataEntry('bank_reconciliation_rules', [BankReconciliationRule::class], 'Bank reconciliation rules', 'financial', ['counterparty_iban'], $erasable, $operationalNote),
            new PersonalDataEntry('open_banking_consents', [OpenBankingConsent::class], 'Open banking consents', 'financial', ['iban'], $evidence, $evidenceNote),
            new PersonalDataEntry('open_banking_payments', [OpenBankingPayment::class], 'Open banking payments', 'financial', ['debtor_iban', 'creditor_iban'], $fiscal, $commercialNote),
            new PersonalDataEntry('payment_approvals', [PaymentApproval::class], 'Payment approvals', 'technical', ['ip_address'], $evidence, $evidenceNote),
            new PersonalDataEntry('payment_lines', [PaymentLine::class], 'Payment lines', 'financial', ['recipient_iban'], $fiscal, $commercialNote),
            new PersonalDataEntry('riba_receipts', [RibaReceipt::class], 'Ri.Ba. receipts', 'financial', ['debtor_tax_code', 'debtor_address'], $fiscal, $commercialNote),
            new PersonalDataEntry('appointments', [Appointment::class], 'Appointments', 'contact', ['address'], $erasable, $operationalNote, ['contact' => 'contact_id'], 'created_at'),
            new PersonalDataEntry('construction_sites', [ConstructionSite::class], 'Construction sites', 'location', ['address'], $erasable, $operationalNote),
            new PersonalDataEntry('work_orders', [WorkOrder::class], 'Work orders', 'location', ['intervention_address'], $erasable, $operationalNote),
            new PersonalDataEntry('vehicles', [Vehicle::class], 'Vehicles', 'identity', ['license_plate'], $erasable, $operationalNote),
            new PersonalDataEntry('workshop_vehicle_stock', [VehicleStockUnit::class], 'Workshop vehicle stock', 'identity', ['license_plate'], $erasable, $operationalNote),
            new PersonalDataEntry('fleet_vehicles', [FleetVehicle::class], 'Fleet vehicles', 'identity', ['license_plate'], $erasable, $operationalNote),
            new PersonalDataEntry('fleet_vehicle_assignments', [FleetVehicleAssignment::class], 'Fleet driver assignments', 'identity', ['driver_label'], $erasable, 'Operational data: the name of an external driver is erased on request or by retention policy; the link to the employee stays with the employment record.', ['employee' => 'employee_id'], 'created_at'),
            new PersonalDataEntry('tl_survey_responses', [EngagementResponse::class], 'Engagement survey responses', 'identity', ['respondent_employee_id'], $erasable, 'The respondent is stored only for a survey that is not anonymous; an anonymous response holds no personal link and is kept as aggregate input.', ['employee' => 'respondent_employee_id'], 'submitted_on'),
            new PersonalDataEntry('tl_survey_answers', [EngagementAnswer::class], 'Engagement survey answers', 'identity', ['text_value'], $erasable, 'Free text answers can contain personal data: erased by retention policy.', [], 'submitted_on'),
            new PersonalDataEntry('weighbridge_vehicles', [WeighbridgeVehicle::class], 'Weighbridge vehicles', 'identity', ['license_plate'], $erasable, $operationalNote, ['contact' => 'contact_id'], 'created_at'),
            new PersonalDataEntry('portal_activity_logs', [PortalActivityLog::class], 'Customer portal activity', 'technical', ['ip_address'], $erasable, 'Access log of the portal: erased with the contact and by retention policy.', ['contact' => 'contact_id'], 'created_at'),
            new PersonalDataEntry('audit_logs', [AuditLog::class], 'Audit log', 'technical', ['ip_address', 'actor_email'], $evidence, $evidenceNote),
            new PersonalDataEntry('preservation_access_logs', [PreservationAccessLog::class], 'Preservation access log', 'technical', ['ip_address'], $evidence, $evidenceNote),
            new PersonalDataEntry('privacy_sensitive_access_logs', [PrivacySensitiveAccessLog::class], 'Sensitive access log', 'technical', ['ip_address'], $evidence, $evidenceNote, ['user' => 'user_id']),
            new PersonalDataEntry('inventory_audit_entries', [InventoryAuditEntry::class], 'Inventory audit entries', 'technical', ['ip_address'], $evidence, $evidenceNote),
            new PersonalDataEntry('approval_decisions', [ApprovalDecision::class], 'Approval decisions', 'technical', ['ip_address'], $evidence, $evidenceNote),
            new PersonalDataEntry('workflow_audit_entries', [WorkflowAuditEntry::class], 'Workflow audit entries', 'technical', ['ip_address'], $evidence, $evidenceNote),
            new PersonalDataEntry('crm_consents', [Consent::class], 'CRM consents', 'contact', ['ip_address'], $evidence, 'Consent history is the proof required by art. 7(1) GDPR: kept; the IP address is pseudonymised on erasure.', ['contact' => 'contact_id', 'lead' => 'lead_id']),
            new PersonalDataEntry('privacy_dsar_requests', [PrivacySubjectRequestDsar::class], 'Data subject requests (governance)', 'identity', ['subject_email', 'subject_full_name'], $evidence, 'Proof of the handling of a request (art. 5(2) GDPR): kept, never rewritten.'),
            new PersonalDataEntry('privacy_consent_ledger', [ConsentLedgerEntry::class], 'Consent ledger', 'technical', ['ip_address'], $evidence, 'Tamper-evident proof of consent (art. 7(1) GDPR): kept, never rewritten; the subject is stored as a hash.'),
            new PersonalDataEntry('crm_leads', [Lead::class], 'CRM leads', 'identity', ['first_name', 'last_name', 'email', 'phone', 'phone_e164', 'company_name'], $erasable, 'Erased on request; a lead converted to a contact is erased through the contact.', ['lead' => 'id'], 'updated_at'),
            new PersonalDataEntry('suppliers', [Supplier::class], 'Suppliers', 'identity', ['fiscal_code'], $fiscal, $commercialNote),
            new PersonalDataEntry('supplier_contacts', [SupplierContact::class], 'Supplier contact persons', 'contact', ['name', 'email', 'phone'], $erasable, $operationalNote, [], 'updated_at'),
            new PersonalDataEntry('safety_data_sheet_deliveries', [SafetyDataSheetDelivery::class], 'Safety data sheet deliveries', 'contact', ['email'], $erasable, $operationalNote, ['contact' => 'contact_id'], 'created_at'),
            new PersonalDataEntry('esm_csat_surveys', [CSATSurvey::class], 'Customer satisfaction surveys', 'contact', ['respondent_email'], $erasable, $operationalNote, [], 'created_at'),
            new PersonalDataEntry('esm_service_technicians', [ServiceTechnician::class], 'Service technicians', 'identity', ['first_name', 'last_name', 'email', 'phone'], $employment, 'Staff data: handled with the employee record.'),
            new PersonalDataEntry('esm_support_mailbox_messages', [SupportMailboxMessage::class], 'Support mailbox messages', 'contact', ['from_email'], $erasable, $operationalNote, [], 'created_at'),
            new PersonalDataEntry('esm_ticket_messages', [TicketMessage::class], 'Ticket messages', 'contact', ['author_email'], $erasable, $operationalNote, [], 'created_at'),
            new PersonalDataEntry('hr_employees', [Employee::class], 'Employees', 'hr', ['first_name', 'last_name', 'full_name', 'work_email', 'personal_email', 'phone', 'birth_date', 'gender', 'nationality', 'tax_code', 'social_security_number', 'emergency_contact'], $employment, 'Contact data erased on request; identity kept while payroll vouchers (10 years) or working time records (5 years) are within the statutory period, to be confirmed.', ['employee' => 'id', 'user' => 'user_id']),
            new PersonalDataEntry('employee_profiles', [EmployeeProfile::class], 'Employee profiles', 'hr', ['social_security_number'], $employment, 'Employment record.', ['user' => 'user_id']),
            new PersonalDataEntry('hr_job_applications', [JobApplication::class], 'Job applications', 'hr', ['first_name', 'last_name', 'email', 'phone', 'cover_note'], $erasable, 'Erased at the end of the consent period by the recruiting purge job, and by retention policy.', ['applicant' => 'id'], 'created_at'),
            new PersonalDataEntry('hr_exit_interviews', [ExitInterview::class], 'Exit interviews', 'hr', ['feedback'], $employment, 'Employment record: the free-text feedback of the leaving employee, encrypted at rest and kept with the personnel file.', ['employee' => 'employee_id']),
            new PersonalDataEntry('hr_interview_notes', [InterviewNote::class], 'Interview notes on applicants', 'hr', ['note'], $erasable, $operationalNote, ['applicant' => 'job_application_id'], 'created_at'),
            new PersonalDataEntry('payroll_employee_profiles', [PayrollEmployeeProfile::class], 'Payroll profiles', 'hr', ['social_security_number', 'iban', 'street', 'house_number', 'postal_code', 'city', 'gender', 'birth_place', 'italian_tax_code', 'swiss_permit_number', 'withholding_tax_code'], $employment, 'Payroll basis: kept with the payslips (10 years, CO art. 958f), to be confirmed.', ['employee' => 'employee_id']),
            new PersonalDataEntry('payroll_employee_children', [PayrollEmployeeChild::class], 'Children of employees (allowances)', 'hr', ['first_name', 'last_name', 'date_of_birth'], $employment, 'Basis of family allowances: kept with the payslips.', ['employee' => 'employee_id']),
            new PersonalDataEntry('payroll_payslips', [Payslip::class], 'Payslips', 'hr', ['withholding_tax_code'], $fiscal, 'Payroll voucher: 10 years (CO art. 958f), to be confirmed. Never deleted.', ['employee' => 'employee_id']),
            new PersonalDataEntry('contract_parties', [ContractParty::class], 'Contract parties', 'contact', ['email'], $fiscal, 'Contract record kept for the limitation period.'),
            new PersonalDataEntry('health_expenses', [HealthExpense::class], 'Health expenses', 'special', ['patient_fiscal_code'], $fiscal, 'Health data used for tax deductions (special category, art. 9 GDPR): kept for the tax period only.'),
            new PersonalDataEntry('assoc_donors', [Donor::class], 'Association donors', 'identity', ['tax_code', 'tax_code_hash', 'email', 'address'], $erasable, $manualNote),
            new PersonalDataEntry('assoc_members', [Member::class], 'Association members', 'special', ['birth_date', 'birth_place', 'gender', 'tax_code', 'tax_code_hash', 'emergency_contact', 'identity_document', 'health_notes', 'allergies', 'internal_notes'], $erasable, 'Erased through the associations erasure service, which checks the fiscal and members book retention (holds are reported).', ['member' => 'id']),
            new PersonalDataEntry('assoc_privacy_subjects', [PrivacySubject::class], 'Association privacy subjects', 'identity', ['tax_code', 'email', 'address'], $erasable, $manualNote),
            new PersonalDataEntry('assoc_sponsors', [Sponsor::class], 'Association sponsors', 'identity', ['email'], $erasable, $manualNote),
            new PersonalDataEntry('cc_children', [Child::class], 'Children in care', 'special', ['first_name', 'last_name', 'birth_date', 'allergies', 'dietary_notes', 'health_notes'], $erasable, 'Health data are erased on request; the identity is kept while billing records are within the statutory period (to be confirmed).', ['child' => 'id']),
            new PersonalDataEntry('cc_reference_persons', [ReferencePerson::class], 'Reference persons of children', 'contact', ['full_name', 'phone', 'email'], $erasable, 'Guardians and pick-up persons: erased with the child.', ['child' => 'child_id']),
            new PersonalDataEntry('hosp_reservations', [Reservation::class], 'Hospitality reservations', 'contact', ['guest_phone', 'guest_email'], $erasable, $operationalNote, ['contact' => 'contact_id'], 'created_at'),
            new PersonalDataEntry('visitors', [Visitor::class], 'Visitors', 'identity', ['first_name', 'last_name', 'company_name', 'email', 'phone', 'document_number'], $erasable, 'Erased by the visitors purge job at the end of the retention period, and by retention policy.', ['contact' => 'contact_id', 'visitor' => 'id'], 'created_at'),
            new PersonalDataEntry('sales_representatives', [SalesRepresentative::class], 'Sales representatives', 'identity', ['email', 'phone'], $employment, 'Staff or agent data.'),
            new PersonalDataEntry('tenant_invitations', [TenantInvitation::class], 'Invitations to join a company', 'contact', ['email'], $erasable, 'Operational data: the invited address is erased on request or by retention policy.', [], 'created_at'),
            new PersonalDataEntry('contact_persons', [ContactPerson::class], 'Contact persons of customers', 'contact', ['first_name', 'last_name', 'email', 'phone', 'mobile'], $erasable, $operationalNote, ['contact' => 'contact_id'], 'updated_at'),
            new PersonalDataEntry('vat_validation_logs', [VatValidationLog::class], 'VAT number validation log', 'contact', ['trader_address'], $evidence, 'Proof of the VAT number check done before a cross-border supply: kept as evidence, never rewritten.', ['contact' => 'contact_id']),
            new PersonalDataEntry('crm_partners', [Partner::class], 'CRM partners', 'contact', ['contact_email'], $erasable, $manualNote),
            new PersonalDataEntry('esm_equipment_passports', [SerializedEquipmentPassport::class], 'Equipment passports', 'location', ['site_address'], $fiscal, $commercialNote),
            new PersonalDataEntry('esm_service_contracts', [ServiceContract::class], 'Service contracts', 'contact', ['report_email'], $fiscal, $commercialNote),
            new PersonalDataEntry('rec_candidate_applications', [CandidateApplication::class], 'Candidate applications', 'hr', ['first_name', 'last_name', 'email', 'phone'], $erasable, 'Erased by the recruiting purge job at the end of the consent period (anonymisation), not by the automated request workflow.'),
            new PersonalDataEntry('rec_candidate_emails', [CandidateEmail::class], 'Emails sent to candidates', 'hr', ['recipient_email'], $erasable, $operationalNote, [], 'created_at'),
            new PersonalDataEntry('us_1099_recipients', [Us1099Recipient::class], 'US 1099 recipients', 'financial', ['address_line'], $fiscal, $commercialNote, ['contact' => 'contact_id']),
            new PersonalDataEntry('comm_campaign_recipients', [CampaignRecipient::class], 'Campaign recipients', 'contact', ['address'], $erasable, $operationalNote, ['contact' => 'contact_id', 'lead' => 'lead_id'], 'created_at'),
            new PersonalDataEntry('comm_conversations', [CommunicationConversation::class], 'Conversations of the shared inbox', 'contact', ['counterparty_address'], $erasable, $operationalNote, ['contact' => 'contact_id'], 'created_at'),
            new PersonalDataEntry('comm_conversation_messages', [ConversationMessage::class], 'Messages of the shared inbox', 'contact', ['sender_address'], $erasable, $operationalNote, [], 'occurred_at'),
            new PersonalDataEntry('quote_signature_requests', [QuoteSignatureRequest::class], 'Online signatures of quotes', 'contact', ['signer_name', 'signer_role', 'ip_address', 'user_agent'], $evidence, 'Proof of acceptance of the quote (signer, time, IP address), kept with the quote for the limitation period.'),
            new PersonalDataEntry('procurement_supplier_quotes', [SupplierQuote::class], 'Supplier quotes', 'technical', ['submitted_ip_address'], $evidence, 'Proof of the bid submission (time, IP address), kept with the request for quotation for the limitation period.'),
            new PersonalDataEntry('contract_signers', [ContractSigner::class], 'Signers of contracts', 'contact', ['email'], $fiscal, 'Contract record kept for the limitation period.'),
            new PersonalDataEntry('hosp_stays', [Stay::class], 'Hospitality stays', 'contact', ['guest_email'], $erasable, $operationalNote, [], 'created_at'),
            new PersonalDataEntry('evt_registrations', [EventRegistration::class], 'Event registrations', 'contact', ['attendee_email'], $erasable, $operationalNote, ['contact' => 'contact_id'], 'created_at'),
            new PersonalDataEntry('users', [User::class], 'User accounts', 'identity', ['name', 'first_name', 'last_name', 'email', 'phone'], $erasable, 'Membership removed per company; the global profile is anonymised only without other memberships and without employment records. Audit trails keep the user id only.', ['user' => 'id'], null, false, ['column' => 'id', 'table' => 'tenant_user', 'parentColumn' => 'user_id']),
        ];

        $entries = [];
        foreach ($list as $entry) {
            $entries[$entry->key()] = $entry;
        }

        return $entries;
    }
}
