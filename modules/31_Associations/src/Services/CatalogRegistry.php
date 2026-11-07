<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Illuminate\Validation\Rule;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Enums\FeeFrequency;
use Modules\Associations\Enums\RegistryKind;
use Modules\Associations\Enums\YearKind;
use Modules\Associations\Models\AccessArea;
use Modules\Associations\Models\AssociationRecord;
use Modules\Associations\Models\AssociativeYear;
use Modules\Associations\Models\BookableResource;
use Modules\Associations\Models\Campaign;
use Modules\Associations\Models\CustomField;
use Modules\Associations\Models\DataAsset;
use Modules\Associations\Models\Department;
use Modules\Associations\Models\Discipline;
use Modules\Associations\Models\Donor;
use Modules\Associations\Models\ExpenseCategory;
use Modules\Associations\Models\Family;
use Modules\Associations\Models\FamilyDiscount;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\GeneralInterestActivity;
use Modules\Associations\Models\LegalParameter;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\PrivacySubject;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Models\Section;
use Modules\Associations\Models\Shift;
use Modules\Associations\Models\SocialRole;
use Modules\Associations\Models\Sponsor;
use Modules\Associations\Models\SponsorContract;
use Modules\Associations\Models\SportCollaborator;
use Modules\Associations\Models\SportContract;
use Modules\Associations\Models\Volunteer;
use Modules\Associations\Models\VolunteerTraining;
use Modules\Associations\Support\AssociationCapability;
use Modules\Associations\Support\TenantRules;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The simple configuration records of the module. They all behave the same, so one set of endpoints serves them; this
 * class says which model each catalog is, who manages it and how it is validated.
 */
final class CatalogRegistry
{
    private const array Models = [
        'registry-types' => RegistryType::class,
        'social-roles' => SocialRole::class,
        'custom-fields' => CustomField::class,
        'families' => Family::class,
        'years' => AssociativeYear::class,
        'membership-types' => MembershipType::class,
        'fee-plans' => FeePlan::class,
        'family-discounts' => FamilyDiscount::class,
        'disciplines' => Discipline::class,
        'departments' => Department::class,
        'resources' => BookableResource::class,
        'access-areas' => AccessArea::class,
        'volunteers' => Volunteer::class,
        'volunteer-trainings' => VolunteerTraining::class,
        'shifts' => Shift::class,
        'campaigns' => Campaign::class,
        'legal-parameters' => LegalParameter::class,
        'privacy-subjects' => PrivacySubject::class,
        'data-assets' => DataAsset::class,
        'donors' => Donor::class,
        'sponsors' => Sponsor::class,
        'sponsor-contracts' => SponsorContract::class,
        'expense-categories' => ExpenseCategory::class,
        'general-interest-activities' => GeneralInterestActivity::class,
        'sport-collaborators' => SportCollaborator::class,
        'sport-contracts' => SportContract::class,
        'sections' => Section::class,
    ];

    private const array FinanceCatalogs = [
        'fee-plans', 'family-discounts', 'campaigns', 'legal-parameters', 'donors', 'sponsors', 'sponsor-contracts', 'expense-categories',
        'general-interest-activities', 'sport-collaborators', 'sport-contracts', 'sections',
    ];

    private const array PrivacyCatalogs = ['privacy-subjects', 'data-assets'];

    private const string Amount = 'regex:/^\d{1,10}(\.\d{1,2})?$/';

    /**
     * @return class-string<AssociationRecord>
     */
    public function model(string $catalog): string
    {
        return self::Models[$catalog] ?? throw new NotFoundHttpException('Unknown catalog.');
    }

    public function writeCapability(string $catalog): AssociationCapability
    {
        if (in_array($catalog, self::PrivacyCatalogs, true)) {
            return AssociationCapability::Privacy;
        }

        return in_array($catalog, self::FinanceCatalogs, true) ? AssociationCapability::Finance : AssociationCapability::Manage;
    }

    /**
     * Data protection catalogs name individuals and are read only by the roles that manage them.
     */
    public function readCapability(string $catalog): AssociationCapability
    {
        return in_array($catalog, self::PrivacyCatalogs, true) ? AssociationCapability::Privacy : AssociationCapability::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(string $catalog, ?string $id): array
    {
        $this->model($catalog);
        $table = (new (self::Models[$catalog]))->getTable();
        $code = static fn (int $maximum = 50): array => ['required', 'string', "max:{$maximum}", 'regex:/^[A-Za-z0-9_.-]+$/', TenantRules::unique($table, 'code', $id)];
        $name = ['required', 'string', 'max:255'];
        $flag = ['sometimes', 'boolean'];
        $order = ['sometimes', 'integer', 'min:0', 'max:65535'];

        return match ($catalog) {
            'registry-types' => [
                'code' => $code(), 'name' => $name, 'kind' => ['sometimes', Rule::enum(RegistryKind::class)],
                'description' => ['sometimes', 'nullable', 'string', 'max:2000'], 'is_active' => $flag, 'sort_order' => $order,
            ],
            'social-roles' => ['code' => $code(), 'name' => $name, 'is_single_holder' => $flag, 'is_active' => $flag, 'sort_order' => $order],
            'custom-fields' => [
                'code' => $code(), 'label' => $name, 'type' => ['sometimes', Rule::in(['text', 'number', 'date', 'boolean', 'select'])],
                'options' => ['sometimes', 'nullable', 'array'], 'options.*' => ['string', 'max:255'],
                'is_required' => $flag, 'is_active' => $flag, 'sort_order' => $order,
            ],
            'families' => ['name' => $name],
            'years' => [
                'code' => $code(30), 'name' => $name, 'kind' => ['sometimes', Rule::enum(YearKind::class)],
                'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
                'status' => ['sometimes', Rule::in(['open', 'closed'])],
            ],
            'membership-types' => [
                'code' => $code(), 'name' => $name,
                'registry_type_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_registry_types')],
                'fee_plan_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_fee_plans')],
                'requires_board_decision' => $flag, 'is_active' => $flag, 'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'fee-plans' => [
                'code' => $code(), 'name' => $name, 'frequency' => ['required', Rule::enum(FeeFrequency::class)],
                'amount' => ['required', self::Amount], 'lessons_included' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
                'instalments' => ['sometimes', 'nullable', 'integer', 'min:2', 'max:60'],
                'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
                'revenue_account_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('chart_of_accounts')],
                'is_active' => $flag,
            ],
            'family-discounts' => [
                'name' => $name, 'from_position' => ['required', 'integer', 'min:2', 'max:100'],
                'percentage' => ['required', 'numeric', 'gt:0', 'max:100'], 'is_active' => $flag,
            ],
            'disciplines' => ['code' => $code(), 'name' => $name, 'is_active' => $flag],
            'departments' => ['name' => $name, 'is_active' => $flag],
            'resources' => [
                'name' => $name, 'kind' => ['sometimes', 'string', 'max:30'], 'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'department_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_departments')], 'is_active' => $flag,
            ],
            'access-areas' => [
                'code' => $code(), 'name' => $name, 'capacity' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'department_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_departments')],
                'check_fee' => $flag, 'check_certificate' => $flag, 'check_course' => $flag, 'is_active' => $flag,
                'required_certificate' => ['sometimes', 'nullable', Rule::enum(CertificateKind::class)],
                'course_margin_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            ],
            'volunteers' => [
                'member_id' => [$id === null ? 'required' : 'sometimes', 'uuid', TenantRules::exists('assoc_members')],
                'activity' => [$id === null ? 'required' : 'sometimes', 'string', 'max:255'],
                'category' => ['sometimes', 'nullable', 'string', 'max:100'],
                'started_on' => [$id === null ? 'required' : 'sometimes', 'date'],
                'ended_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:started_on'],
                'is_occasional' => $flag,
                'insurer' => ['sometimes', 'nullable', 'string', 'max:255'],
                'insurance_policy' => ['sometimes', 'nullable', 'string', 'max:100'],
                'insurance_expires_on' => ['sometimes', 'nullable', 'date'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'volunteer-trainings' => [
                'volunteer_id' => [$id === null ? 'required' : 'sometimes', 'uuid', TenantRules::exists('assoc_volunteers')],
                'title' => [$id === null ? 'required' : 'sometimes', 'string', 'max:255'],
                'completed_on' => [$id === null ? 'required' : 'sometimes', 'date'],
                'expires_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:completed_on'],
            ],
            'shifts' => [
                'title' => [$id === null ? 'required' : 'sometimes', 'string', 'max:255'],
                'service' => ['sometimes', 'nullable', 'string', 'max:100'],
                'location' => ['sometimes', 'nullable', 'string', 'max:255'],
                'starts_at' => [$id === null ? 'required' : 'sometimes', 'date'],
                'ends_at' => [$id === null ? 'required' : 'sometimes', 'date', 'after:starts_at'],
                'required_volunteers' => ['sometimes', 'integer', 'min:1', 'max:1000'],
                'status' => ['sometimes', Rule::in([Shift::Planned, Shift::Cancelled])],
                'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'campaigns' => [
                'code' => $code(), 'name' => $name,
                'kind' => ['sometimes', Rule::in([Campaign::Regular, Campaign::Occasional])],
                'purpose' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'starts_on' => ['sometimes', 'nullable', 'date'],
                'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
                'target_amount' => ['sometimes', 'nullable', self::Amount],
                'cost_center_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('cost_centers')],
                'revenue_account_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('chart_of_accounts')],
                'expense_account_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('chart_of_accounts')],
                'status' => ['sometimes', Rule::in(['active', 'closed'])],
            ],
            'legal-parameters' => [
                'code' => [$id === null ? 'required' : 'sometimes', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/'],
                'year' => [$id === null ? 'required' : 'sometimes', 'integer', 'min:2000', 'max:2100'],
                'value' => [$id === null ? 'required' : 'sometimes', 'string', 'max:100', 'regex:/^-?\d{1,12}(\.\d{1,6})?$/'],
                'source_reference' => [$id === null ? 'required' : 'sometimes', 'string', 'max:500'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'privacy-subjects' => [
                'role' => [$id === null ? 'required' : 'sometimes', Rule::in(PrivacySubject::Roles)],
                'name' => [$id === null ? 'required' : 'sometimes', 'string', 'max:255'],
                'representative' => ['sometimes', 'nullable', 'string', 'max:255'],
                'tax_code' => ['sometimes', 'nullable', 'string', 'max:32'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'address' => ['sometimes', 'nullable', 'string', 'max:500'],
                'member_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_members')],
                'scope' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'appointed_on' => ['sometimes', 'nullable', 'date'],
                'ended_on' => ['sometimes', 'nullable', 'date'],
            ],
            'data-assets' => [
                'name' => [$id === null ? 'required' : 'sometimes', 'string', 'max:255'],
                'kind' => [$id === null ? 'required' : 'sometimes', Rule::in(['physical', 'digital', 'human'])],
                'location' => ['sometimes', 'nullable', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'confidentiality' => [$id === null ? 'required' : 'sometimes', 'integer', 'between:1,4'],
                'integrity' => [$id === null ? 'required' : 'sometimes', 'integer', 'between:1,4'],
                'availability' => [$id === null ? 'required' : 'sometimes', 'integer', 'between:1,4'],
                'owner' => ['sometimes', 'nullable', 'string', 'max:255'],
                'activity_family_ids' => ['sometimes', 'nullable', 'array'],
                'activity_family_ids.*' => ['uuid'],
            ],
            'donors' => [
                'kind' => ['sometimes', Rule::in(['person', 'organization'])],
                'member_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_members')],
                'contact_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('contacts')],
                'name' => [$id === null ? 'required' : 'sometimes', 'string', 'max:255'],
                'tax_code' => ['sometimes', 'nullable', 'string', 'max:32'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'address' => ['sometimes', 'nullable', 'string', 'max:500'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'sponsors' => [
                'name' => $name,
                'contact_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('contacts')],
                'vat_number' => ['sometimes', 'nullable', 'string', 'max:30'],
                'email' => ['sometimes', 'nullable', 'email', 'max:255'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'sponsor-contracts' => [
                'sponsor_id' => [$id === null ? 'required' : 'sometimes', 'uuid', TenantRules::exists('assoc_sponsors')],
                'title' => [$id === null ? 'required' : 'sometimes', 'string', 'max:255'],
                'starts_on' => [$id === null ? 'required' : 'sometimes', 'date'],
                'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
                'amount' => [$id === null ? 'required' : 'sometimes', self::Amount],
                'consideration' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'status' => ['sometimes', Rule::in(['draft', 'active', 'ended'])],
                'campaign_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_campaigns')],
                'invoice_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('invoices')],
            ],
            'expense-categories' => [
                'scope' => [$id === null ? 'required' : 'sometimes', Rule::in([ExpenseCategory::FivePerMille, 'campaign'])],
                'code' => [$id === null ? 'required' : 'sometimes', 'string', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/'],
                'name' => $name,
                'sort_order' => $order,
            ],
            'general-interest-activities' => [
                'kind' => ['sometimes', Rule::in([GeneralInterestActivity::GeneralInterest, GeneralInterestActivity::Secondary])],
                'reference' => [$id === null ? 'required' : 'sometimes', 'string', 'max:20'],
                'description' => [$id === null ? 'required' : 'sometimes', 'string', 'max:1000'],
                'statute_clause' => ['sometimes', 'nullable', 'string', 'max:255'],
                'cost_center_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('cost_centers')],
                'is_active' => $flag,
            ],
            'sport-collaborators' => [
                'member_id' => [$id === null ? 'required' : 'sometimes', 'uuid', TenantRules::exists('assoc_members')],
                'role' => [$id === null ? 'required' : 'sometimes', 'string', 'max:100'],
                'qualification' => ['sometimes', 'nullable', 'string', 'max:255'],
                'discipline_id' => ['sometimes', 'nullable', 'uuid', TenantRules::exists('assoc_disciplines')],
                'starts_on' => [$id === null ? 'required' : 'sometimes', 'date'],
                'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
                'insurer' => ['sometimes', 'nullable', 'string', 'max:255'],
                'insurance_policy' => ['sometimes', 'nullable', 'string', 'max:100'],
                'insurance_expires_on' => ['sometimes', 'nullable', 'date'],
                'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'sport-contracts' => [
                'collaborator_id' => [$id === null ? 'required' : 'sometimes', 'uuid', TenantRules::exists('assoc_sport_collaborators')],
                'kind' => [$id === null ? 'required' : 'sometimes', 'string', 'max:60'],
                'starts_on' => [$id === null ? 'required' : 'sometimes', 'date'],
                'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
                'agreed_amount' => ['sometimes', 'nullable', self::Amount],
                'agreed_period' => ['sometimes', 'nullable', Rule::in(['hour', 'day', 'month', 'season', 'total'])],
                'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'status' => ['sometimes', Rule::in(['active', 'ended'])],
            ],
            'sections' => [
                'code' => $code(), 'name' => $name,
                'cost_center_id' => [$id === null ? 'required' : 'sometimes', 'uuid', TenantRules::exists('cost_centers'), TenantRules::unique('assoc_sections', 'cost_center_id', $id)],
                'is_active' => $flag,
            ],
        };
    }
}
