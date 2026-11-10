<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Privacy\PrivacyDataBreach;
use App\Models\Privacy\PrivacyDataSubjectRequest;
use App\Models\Privacy\PrivacyProcessingActivity;
use App\Models\Privacy\PrivacySensitiveAccessLog;
use App\Models\Privacy\PrivacySubprocessor;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\HumanResources\Models\Employee;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\SalaryCertificate;

/**
 * Tenant-wide GDPR (EU 2016/679) and Swiss nLPD (RS 235.1 / DPOOP RS 235.11) compliance service.
 */
final class GlobalPrivacyComplianceService
{
    /** Countries recognized as providing adequate protection by the Swiss Federal Council (Annex 1 DPOOP). */
    public const array NLPD_ADEQUATE_COUNTRIES = [
        'CH', 'LI', 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU',
        'IS', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES',
        'SE', 'GB', 'AD', 'AR', 'CA', 'FO', 'GG', 'IL', 'IM', 'JE', 'NZ', 'UY', 'KR', 'JP',
    ];

    /**
     * @return Collection<int, PrivacyProcessingActivity>
     */
    public function listProcessingActivities(string $tenantId): Collection
    {
        return PrivacyProcessingActivity::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderBy('title')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function storeProcessingActivity(string $tenantId, array $payload): PrivacyProcessingActivity
    {
        return PrivacyProcessingActivity::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'title' => (string) $payload['title'],
            'purpose' => (string) $payload['purpose'],
            'legal_basis' => (string) $payload['legal_basis'],
            'data_categories' => array_values((array) ($payload['data_categories'] ?? [])),
            'subject_categories' => array_values((array) ($payload['subject_categories'] ?? [])),
            'recipients' => isset($payload['recipients']) ? array_values((array) $payload['recipients']) : null,
            'cross_border_countries' => isset($payload['cross_border_countries']) ? array_values((array) $payload['cross_border_countries']) : null,
            'transfer_safeguards' => $payload['transfer_safeguards'] ?? null,
            'retention_period' => (string) $payload['retention_period'],
            'security_measures' => $payload['security_measures'] ?? null,
            'status' => (string) ($payload['status'] ?? 'active'),
            'version' => (int) ($payload['version'] ?? 1),
        ]);
    }

    /**
     * @return Collection<int, PrivacySubprocessor>
     */
    public function listSubprocessors(string $tenantId): Collection
    {
        return PrivacySubprocessor::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function storeSubprocessor(string $tenantId, array $payload): PrivacySubprocessor
    {
        $countryCode = strtoupper((string) $payload['country_code']);
        $isAdequate = in_array($countryCode, self::NLPD_ADEQUATE_COUNTRIES, true);

        return PrivacySubprocessor::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'name' => (string) $payload['name'],
            'service_type' => (string) $payload['service_type'],
            'country_code' => $countryCode,
            'is_adequate_country_nlpd' => $isAdequate,
            'safeguard_mechanism' => $payload['safeguard_mechanism'] ?? ($isAdequate ? 'adequacy_decision' : 'scc_fdpic'),
            'dpa_signed_at' => $payload['dpa_signed_at'] ?? null,
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDataSubjectRequest(string $tenantId, array $payload, ?string $actorId = null): PrivacyDataSubjectRequest
    {
        $now = CarbonImmutable::now();

        return PrivacyDataSubjectRequest::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'request_type' => (string) $payload['request_type'],
            'regulation' => (string) ($payload['regulation'] ?? 'nLPD'),
            'subject_type' => (string) $payload['subject_type'],
            'subject_id' => (string) $payload['subject_id'],
            'status' => 'received',
            'deadline_at' => $now->addDays(30),
            'notes' => $payload['notes'] ?? null,
            'handled_by' => $actorId,
        ]);
    }

    /**
     * Exports a portable machine-readable JSON dossier for a Contact or Employee (GDPR Art. 15/20, nLPD Art. 25/28).
     *
     * @return array<string, mixed>
     */
    public function exportSubjectDossier(string $tenantId, string $subjectType, string $subjectId, ?string $actorId = null, ?string $ip = null): array
    {
        $this->logSensitiveAccess($tenantId, $actorId, $subjectType, $subjectId, 'full_subject_dossier', 'portability_export', $ip);

        if ($subjectType === 'contact') {
            $contact = Contact::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->whereKey($subjectId)
                ->with(['addresses'])
                ->firstOrFail();

            $invoices = Invoice::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $contact->id)
                ->orderByDesc('issue_date')
                ->get(['id', 'invoice_number', 'issue_date', 'due_date', 'total', 'currency', 'status']);

            return [
                'schema' => 'urn:fatturafacile:privacy:portability:1.0',
                'regulations' => ['GDPR Art. 15 & 20', 'Swiss nLPD Art. 25 & 28'],
                'exported_at' => CarbonImmutable::now()->toIso8601String(),
                'subject_type' => 'contact',
                'subject_id' => (string) $contact->id,
                'personal_data' => [
                    'company_name' => $contact->company_name,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'vat_number' => $contact->vat_number,
                    'fiscal_code' => $contact->fiscal_code,
                    'uid_number' => $contact->uid_number,
                    'iban' => $contact->iban,
                    'addresses' => $contact->addresses->toArray(),
                ],
                'commercial_records' => [
                    'invoices' => $invoices->toArray(),
                ],
            ];
        }

        if ($subjectType === 'employee') {
            $employee = Employee::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->whereKey($subjectId)
                ->with(['contracts'])
                ->firstOrFail();

            $payrollProfile = PayrollEmployeeProfile::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employee->id)
                ->first();

            $payslips = Payslip::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employee->id)
                ->get(['id', 'gross_amount', 'net_amount', 'currency', 'created_at']);

            $certificates = SalaryCertificate::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)
                ->where('employee_id', $employee->id)
                ->get(['id', 'year', 'gross_salary_total', 'net_salary', 'issued_at']);

            return [
                'schema' => 'urn:fatturafacile:privacy:portability:1.0',
                'regulations' => ['GDPR Art. 15 & 20', 'Swiss nLPD Art. 25 & 28'],
                'exported_at' => CarbonImmutable::now()->toIso8601String(),
                'subject_type' => 'employee',
                'subject_id' => (string) $employee->id,
                'personal_data' => [
                    'employee_code' => $employee->employee_code,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'personal_email' => $employee->personal_email,
                    'work_email' => $employee->work_email,
                    'phone' => $employee->phone,
                    'birth_date' => $employee->birth_date?->toDateString(),
                    'hire_date' => $employee->hire_date?->toDateString(),
                    'termination_date' => $employee->termination_date?->toDateString(),
                    'social_security_number' => $employee->social_security_number,
                    'tax_code' => $employee->tax_code,
                ],
                'payroll_profile' => $payrollProfile ? [
                    'tax_canton' => $payrollProfile->tax_canton,
                    'swiss_permit_type' => $payrollProfile->swiss_permit_type,
                    'swiss_permit_Expires_on' => $payrollProfile->swiss_permit_expires_on?->toDateString(),
                    'withholding_tax_tariff' => $payrollProfile->withholding_tax_tariff,
                ] : null,
                'employment_contracts' => $employee->contracts->toArray(),
                'payslips' => $payslips->toArray(),
                'salary_certificates' => $certificates->toArray(),
            ];
        }

        throw new InvalidArgumentException("Unsupported subject_type [{$subjectType}].");
    }

    /**
     * Evaluates legal holds (CO Art. 958f 10-year accounting retention & ArG Art. 46 5-year time retention)
     * and executes anonymization/erasure or restriction of processing (GDPR Art. 17/18, nLPD Art. 32).
     */
    public function executeErasureOrRestriction(string $tenantId, PrivacyDataSubjectRequest $dsr, ?string $actorId = null): PrivacyDataSubjectRequest
    {
        return DB::transaction(function () use ($tenantId, $dsr, $actorId): PrivacyDataSubjectRequest {
            $now = CarbonImmutable::now();
            $tenYearsAgo = $now->subYears(10);
            $fiveYearsAgo = $now->subYears(5);

            $legalHolds = [];
            $erasedFields = [];

            if ($dsr->subject_type === 'contact') {
                $contact = Contact::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->whereKey($dsr->subject_id)
                    ->firstOrFail();

                $recentInvoices = Invoice::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('contact_id', $contact->id)
                    ->whereDate('issue_date', '>=', $tenYearsAgo->toDateString())
                    ->count();

                if ($recentInvoices > 0) {
                    $legalHolds[] = [
                        'statute' => 'Swiss CO Art. 958f',
                        'retention_years' => 10,
                        'reason' => "Contact has {$recentInvoices} accounting/invoice record(s) within the mandatory 10-year statutory retention window.",
                    ];
                }

                $contact->update([
                    'email' => "anonymized-{$contact->id}@redacted.invalid",
                    'phone' => null,
                    'pec' => null,
                    'iban' => null,
                    'qr_iban' => null,
                    'first_name' => $legalHolds === [] ? 'REDACTED' : $contact->first_name,
                    'last_name' => $legalHolds === [] ? 'REDACTED' : $contact->last_name,
                    'company_name' => $legalHolds === [] ? 'REDACTED CONTACT' : $contact->company_name,
                    'processing_restricted_at' => $now,
                    'anonymized_at' => $legalHolds === [] ? $now : null,
                ]);

                $erasedFields = ['email', 'phone', 'pec', 'iban', 'qr_iban'];
                if ($legalHolds === []) {
                    $erasedFields = array_merge($erasedFields, ['first_name', 'last_name', 'company_name']);
                }
            } elseif ($dsr->subject_type === 'employee') {
                $employee = Employee::withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $tenantId)
                    ->whereKey($dsr->subject_id)
                    ->firstOrFail();

                $recentPayslips = Payslip::withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)
                    ->where('created_at', '>=', $tenYearsAgo)
                    ->count();

                if ($recentPayslips > 0) {
                    $legalHolds[] = [
                        'statute' => 'Swiss CO Art. 958f',
                        'retention_years' => 10,
                        'reason' => "Employee has {$recentPayslips} payroll/accounting voucher(s) within the mandatory 10-year statutory retention period.",
                    ];
                }

                $recentAttendance = DB::table('hr_attendance_records')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)
                    ->whereDate('work_date', '>=', $fiveYearsAgo->toDateString())
                    ->count();

                if ($recentAttendance > 0) {
                    $legalHolds[] = [
                        'statute' => 'Swiss ArG Art. 46 / ArGV 1 Art. 73',
                        'retention_years' => 5,
                        'reason' => "Employee has {$recentAttendance} working-time record(s) within the mandatory 5-year Labour Law retention window.",
                    ];
                }

                $employee->update([
                    'personal_email' => null,
                    'phone' => null,
                    'emergency_contact' => null,
                    'first_name' => $legalHolds === [] ? 'REDACTED' : $employee->first_name,
                    'last_name' => $legalHolds === [] ? 'REDACTED' : $employee->last_name,
                    'processing_restricted_at' => $now,
                    'anonymized_at' => $legalHolds === [] ? $now : null,
                ]);

                $erasedFields = ['personal_email', 'phone', 'emergency_contact'];
                if ($legalHolds === []) {
                    $erasedFields = array_merge($erasedFields, ['first_name', 'last_name']);
                }
            } else {
                throw new InvalidArgumentException("Unsupported subject_type [{$dsr->subject_type}].");
            }

            $status = $legalHolds === [] ? 'completed' : 'partially_completed_legal_hold';

            $dsr->update([
                'status' => $status,
                'legal_holds' => $legalHolds,
                'erasure_summary' => [
                    'erased_fields' => $erasedFields,
                    'processing_restricted' => true,
                    'full_anonymization' => $legalHolds === [],
                    'executed_at' => $now->toIso8601String(),
                ],
                'handled_by' => $actorId,
                'completed_at' => $now,
            ]);

            return $dsr->fresh();
        });
    }

    /**
     * Registers a personal data breach (GDPR Art. 33 72h rule & Swiss nLPD Art. 24 FDPIC notification).
     *
     * @param  array<string, mixed>  $payload
     */
    public function reportDataBreach(string $tenantId, array $payload): PrivacyDataBreach
    {
        $discoveredAt = isset($payload['discovered_at'])
            ? CarbonImmutable::parse((string) $payload['discovered_at'])
            : CarbonImmutable::now();

        $severity = (string) ($payload['severity'] ?? 'high');
        $requiresAuthority = in_array($severity, ['medium', 'high', 'critical'], true);
        $requiresSubjects = in_array($severity, ['high', 'critical'], true);

        return PrivacyDataBreach::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'title' => (string) $payload['title'],
            'severity' => $severity,
            'discovered_at' => $discoveredAt,
            'notification_deadline_at' => $discoveredAt->addHours(72),
            'affected_records_count' => (int) ($payload['affected_records_count'] ?? 0),
            'data_categories' => array_values((array) ($payload['data_categories'] ?? [])),
            'likely_consequences' => $payload['likely_consequences'] ?? null,
            'mitigation_measures' => $payload['mitigation_measures'] ?? null,
            'requires_authority_notification' => $requiresAuthority,
            'requires_subject_notification' => $requiresSubjects,
            'status' => (string) ($payload['status'] ?? 'investigating'),
        ]);
    }

    /**
     * Records a read/export access on sensitive personal data (Swiss DPOOP Art. 4 logging requirement).
     */
    public function logSensitiveAccess(
        string $tenantId,
        ?string $userId,
        string $subjectType,
        string $subjectId,
        string $dataCategory,
        string $action = 'read',
        ?string $ipAddress = null,
    ): PrivacySensitiveAccessLog {
        return PrivacySensitiveAccessLog::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId !== null && Str::isUuid($userId) ? $userId : null,
            'subject_type' => $subjectType,
            'subject_id' => substr($subjectId, 0, 64),
            'data_category' => $dataCategory,
            'action' => $action,
            'ip_address' => $ipAddress,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
