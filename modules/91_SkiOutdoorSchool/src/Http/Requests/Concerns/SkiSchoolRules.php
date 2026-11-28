<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemType;

/**
 * Shared validation building blocks of the ski school requests: references stay inside the active company, enumerations come from the domain.
 */
trait SkiSchoolRules
{
    protected function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }

    protected function inTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->tenantId());
    }

    /** The levels are the standard taxonomy (no company) or the custom levels of the company. */
    protected function levelExists(): Exists
    {
        $tenantId = $this->tenantId();

        return Rule::exists('group_course_levels', 'id')->where(static fn (Builder $query): Builder => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function itemRules(): array
    {
        return [
            'items' => ['nullable', 'array', 'max:20'],
            'items.*.item_type' => ['required', Rule::in(PackageItemType::values())],
            'items.*.description' => ['nullable', 'string', 'max:200'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'items.*.days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'items.*.unit_price_net' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'items.*.vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.product_id' => ['nullable', 'uuid', $this->inTenant('products')],
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function disciplineRule(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', Rule::in(Discipline::values())];
    }
}
