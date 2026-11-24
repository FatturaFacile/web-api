<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance;

use App\Models\Privacy\DataRetentionPolicyRule;
use App\Models\Privacy\ProcessingActivityRecordRopa;
use Enterprise\MultiTenant\Scopes\TenantScope;
use RuntimeException;

/**
 * Seeds the five standard CH/IT processing activities (ROPA-001..005) and their retention rules from the canonical
 * catalogue database/data/privacy/default_ropa_templates.json. Idempotent: existing codes are left as the DPO edited
 * them, only the missing ones are created.
 */
final class RopaDefaultTemplateService
{
    /**
     * @return array<string, mixed>
     */
    public function catalogue(): array
    {
        $path = database_path('data/privacy/default_ropa_templates.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('The RoPA template catalogue is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @return array{ropa_created: int, ropa_existing: int, rules_created: int, rules_existing: int}
     */
    public function seed(string $tenantId): array
    {
        $catalogue = $this->catalogue();
        $result = ['ropa_created' => 0, 'ropa_existing' => 0, 'rules_created' => 0, 'rules_existing' => 0];

        foreach ($catalogue['templates'] as $template) {
            $existing = ProcessingActivityRecordRopa::withoutGlobalScope(TenantScope::class)->withTrashed()
                ->where('tenant_id', $tenantId)->where('activity_code', $template['activity_code'])->exists();
            if ($existing) {
                $result['ropa_existing']++;

                continue;
            }

            unset($template['legal_basis_reference']);
            ProcessingActivityRecordRopa::withoutGlobalScope(TenantScope::class)->create($template + ['tenant_id' => $tenantId, 'is_active' => true]);
            $result['ropa_created']++;
        }

        foreach ($catalogue['retention_rules'] as $rule) {
            $existing = DataRetentionPolicyRule::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)->where('module_code', $rule['module_code'])->exists();
            if ($existing) {
                $result['rules_existing']++;

                continue;
            }

            DataRetentionPolicyRule::withoutGlobalScope(TenantScope::class)->create($rule + ['tenant_id' => $tenantId, 'is_enabled' => true]);
            $result['rules_created']++;
        }

        return $result;
    }
}
