<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Database\Seeders\ChartOfAccountTemplateSeeder;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\AccountMapping;
use Modules\GeneralLedger\Models\ChartOfAccount;

final readonly class SeedDefaultMappingsHandler
{
    public function handle(SeedDefaultMappingsCommand $command): int
    {
        $definitions = ChartOfAccountTemplateSeeder::definitions();
        $accountsByCode = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $command->tenantId)
            ->get()
            ->keyBy('account_code');

        $created = 0;

        foreach ($definitions as $key => $definition) {
            if ($command->sourceStandard !== null && $command->sourceStandard !== $key) {
                continue;
            }

            /** @var list<array<string, mixed>> $defaultMappings */
            $defaultMappings = $definition['default_mappings'] ?? [];
            $standard = $definition['standard'];

            foreach ($defaultMappings as $mapping) {
                $sourceCode = (string) ($mapping['source_code'] ?? '');
                $targetCode = (string) ($mapping['target_code'] ?? '');
                $targetStandard = (string) ($mapping['target_standard'] ?? '');
                $sourceAccount = $accountsByCode->get($sourceCode);

                if (! $sourceAccount instanceof ChartOfAccount || $targetCode === '' || $targetStandard === '') {
                    continue;
                }

                $exists = AccountMapping::withoutGlobalScopes()
                    ->where('tenant_id', $command->tenantId)
                    ->where('source_account_id', $sourceAccount->id)
                    ->where('target_standard', $targetStandard)
                    ->where('target_account_code', $targetCode)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $targetAccount = $accountsByCode->get($targetCode);

                AccountMapping::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $command->tenantId,
                    'company_id' => $command->companyId ?? $sourceAccount->company_id,
                    'source_account_id' => $sourceAccount->id,
                    'target_account_id' => $targetAccount?->id,
                    'source_standard' => $standard,
                    'target_standard' => $targetStandard,
                    'target_account_code' => $targetCode,
                    'target_account_name' => (string) ($mapping['target_name'] ?? $targetCode),
                    'mapping_type' => (string) ($mapping['mapping_type'] ?? 'direct'),
                    'is_bidirectional' => (bool) ($mapping['is_bidirectional'] ?? true),
                    'allocation_percentage' => (string) ($mapping['allocation_percentage'] ?? '100.0000'),
                    'notes' => $mapping['notes'] ?? null,
                    'is_active' => true,
                ]);

                $created++;
            }
        }

        return $created;
    }
}
