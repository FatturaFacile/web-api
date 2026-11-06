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
use Modules\Associations\Models\CustomField;
use Modules\Associations\Models\Department;
use Modules\Associations\Models\Discipline;
use Modules\Associations\Models\Family;
use Modules\Associations\Models\FamilyDiscount;
use Modules\Associations\Models\FeePlan;
use Modules\Associations\Models\MembershipType;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Models\SocialRole;
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
    ];

    private const array FinanceCatalogs = ['fee-plans', 'family-discounts'];

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
        return in_array($catalog, self::FinanceCatalogs, true) ? AssociationCapability::Finance : AssociationCapability::Manage;
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
        };
    }
}
