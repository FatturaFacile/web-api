<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Application;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Platform\Identity\Domain\Enums\FieldVisibility;
use Modules\Platform\Identity\Domain\Services\FieldMasker;

/**
 * Applies the field policies of the roles of a user to payloads and exports. When several roles disagree the most
 * restrictive visibility wins (hidden over masked over visible). A field without any policy stays visible. The raw value of
 * a masked or hidden field is dropped here, so it can never reach a response or an export.
 */
final class FieldPolicyResolver
{
    /** @var list<string> `resource.field` keys masked or hidden during the current request */
    private array $applied = [];

    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly FieldMasker $masker,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(?User $user, string $resource, array $payload, ?string $companyId = null): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        if ($user === null || $tenantId === null) {
            return $payload;
        }

        [$visibility, $kinds] = $this->policiesFor($user, $tenantId, $companyId, $resource);

        if ($visibility === []) {
            return $payload;
        }

        $result = $this->masker->apply($payload, $visibility, $kinds);

        foreach ($visibility as $field => $state) {
            if ($state !== FieldVisibility::Visible && array_key_exists($field, $payload)) {
                $this->applied[] = "{$resource}.{$field}";
            }
        }

        return $result['data'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function applyToRows(?User $user, string $resource, array $rows, ?string $companyId = null): array
    {
        return array_map(fn (array $row): array => $this->apply($user, $resource, $row, $companyId), $rows);
    }

    /**
     * @return list<string>
     */
    public function appliedFields(): array
    {
        return array_values(array_unique($this->applied));
    }

    public function reset(): void
    {
        $this->applied = [];
    }

    /**
     * @return array{0: array<string, FieldVisibility>, 1: array<string, string>}
     */
    private function policiesFor(User $user, string $tenantId, ?string $companyId, string $resource): array
    {
        $rank = [FieldVisibility::Visible->value => 0, FieldVisibility::Masked->value => 1, FieldVisibility::Hidden->value => 2];
        $visibility = [];
        $kinds = [];

        foreach ($this->permissions->snapshot($user, $tenantId, $companyId)['field_policies'] as $policy) {
            if ($policy['resource'] !== $resource) {
                continue;
            }

            $field = $policy['field'];
            $current = $visibility[$field] ?? null;

            if ($current === null || $rank[$policy['visibility']] > $rank[$current->value]) {
                $visibility[$field] = FieldVisibility::from($policy['visibility']);
            }
            $kinds[$field] = $policy['kind'];
        }

        return [$visibility, $kinds];
    }
}
