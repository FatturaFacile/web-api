<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Privacy\PrivacyRetentionPolicy;
use Enterprise\MultiTenant\Scopes\TenantScope;
use InvalidArgumentException;

/**
 * Suggested retention periods per table, as DATA the company must confirm. They are NOT legal requirements, they are
 * not verified against any statute, and they are never applied automatically: a policy exists only after the company
 * saves it (or adopts a suggestion explicitly, which creates it inactive). Only tables the registry lets age out can
 * appear here (erasable data with an age column); accounting, payroll and evidence records have a fixed retention.
 * The numbers are common operational practice (days since the age column of the table) for the data protection officer
 * to review.
 */
final class RetentionSuggestions
{
    /**
     * @var array<string, array{days: int, rationale: string}>
     */
    public const array Suggested = [
        'crm_leads' => ['days' => 730, 'rationale' => 'Leads without activity for two years are unlikely to still be of interest.'],
        'supplier_contacts' => ['days' => 1095, 'rationale' => 'Contact persons of suppliers not updated for three years.'],
        'appointments' => ['days' => 365, 'rationale' => 'Addresses of appointments are not needed once the appointment is a year old.'],
        'weighbridge_vehicles' => ['days' => 730, 'rationale' => 'Plates of vehicles that did not return for two years.'],
        'portal_activity_logs' => ['days' => 180, 'rationale' => 'Access logs of the customer portal for security review, six months.'],
        'safety_data_sheet_deliveries' => ['days' => 730, 'rationale' => 'Recipients of safety data sheets: two years after the delivery.'],
        'esm_csat_surveys' => ['days' => 730, 'rationale' => 'Customer satisfaction answers older than two years.'],
        'esm_support_mailbox_messages' => ['days' => 730, 'rationale' => 'Sender addresses of support emails older than two years.'],
        'esm_ticket_messages' => ['days' => 1095, 'rationale' => 'Author addresses of ticket messages older than three years.'],
        'hr_job_applications' => ['days' => 180, 'rationale' => 'Applications of candidates not hired: six months, unless the candidate consented to a longer period (the recruiting purge job honours the consent).'],
        'hr_interview_notes' => ['days' => 180, 'rationale' => 'Interview notes follow the application.'],
        'hosp_reservations' => ['days' => 365, 'rationale' => 'Guest contact data of reservations older than a year.'],
        'visitors' => ['days' => 90, 'rationale' => 'Visitors register: three months. The visitors module has its own retention setting and purge job, which takes precedence.'],
    ];

    public function __construct(private PersonalDataRegistry $registry) {}

    /**
     * The suggestions with the policy the company has now, if any.
     *
     * @return list<array{entry_key: string, label: string, personal_columns: list<string>, age_column: string|null, suggested_retention_days: int, rationale: string, requires_confirmation: bool, applied_automatically: bool, basis: string, current_policy: array{retention_days: int, is_active: bool}|null}>
     */
    public function list(string $tenantId): array
    {
        $policies = PrivacyRetentionPolicy::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->get()->keyBy('entry_key');
        $list = [];

        foreach (self::Suggested as $key => $suggestion) {
            $entry = $this->registry->find($key);
            if ($entry === null || ! $entry->supportsRetentionPolicy()) {
                continue;
            }

            $policy = $policies->get($key);
            $list[] = [
                'entry_key' => $key,
                'label' => $entry->label,
                'personal_columns' => $entry->personalColumns,
                'age_column' => $entry->ageColumn,
                'suggested_retention_days' => $suggestion['days'],
                'rationale' => $suggestion['rationale'],
                'requires_confirmation' => true,
                'applied_automatically' => false,
                'basis' => 'Operational suggestion, not a legal requirement and not verified against any statute: to be confirmed by the data protection officer.',
                'current_policy' => $policy === null ? null : ['retention_days' => (int) $policy->retention_days, 'is_active' => (bool) $policy->is_active],
            ];
        }

        return $list;
    }

    /**
     * Creates the policy from the suggestion, inactive until the company activates it. Refuses to overwrite a policy.
     */
    public function adopt(string $tenantId, string $entryKey, PrivacyRetentionService $retention, ?int $retentionDays = null, bool $activate = false): PrivacyRetentionPolicy
    {
        $suggestion = self::Suggested[$entryKey] ?? throw new InvalidArgumentException("There is no suggestion for [{$entryKey}].");

        if (PrivacyRetentionPolicy::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->where('entry_key', $entryKey)->exists()) {
            throw new InvalidArgumentException("The company already has a policy for [{$entryKey}]: change it instead.");
        }

        return $retention->savePolicy($tenantId, $entryKey, $retentionDays ?? $suggestion['days'], $activate, 'Adopted from the suggestion: '.$suggestion['rationale']);
    }
}
