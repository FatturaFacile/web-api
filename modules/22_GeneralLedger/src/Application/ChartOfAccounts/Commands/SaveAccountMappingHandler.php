<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Commands;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\AccountMapping;
use Modules\GeneralLedger\Models\ChartOfAccount;

final readonly class SaveAccountMappingHandler
{
    public function handle(SaveAccountMappingCommand $command): AccountMapping
    {
        $attrs = $command->attributes;
        $sourceId = (string) ($attrs['source_account_id'] ?? '');
        $targetId = isset($attrs['target_account_id']) && $attrs['target_account_id'] !== ''
            ? (string) $attrs['target_account_id']
            : null;

        /** @var ChartOfAccount $sourceAccount */
        $sourceAccount = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $command->tenantId)
            ->findOrFail($sourceId);

        if ($targetId !== null && $targetId === $sourceAccount->id) {
            throw ValidationException::withMessages([
                'target_account_id' => [__('general_ledger_errors.mapping.self_mapping')],
            ]);
        }

        $targetAccount = null;
        if ($targetId !== null) {
            $targetAccount = ChartOfAccount::withoutGlobalScopes()
                ->where('tenant_id', $command->tenantId)
                ->findOrFail($targetId);
        }

        $sourceStandard = (string) ($attrs['source_standard'] ?? $sourceAccount->template_code ?? 'custom');
        $targetStandard = (string) ($attrs['target_standard'] ?? ($targetAccount !== null ? ($targetAccount->template_code ?? 'custom') : 'custom'));
        $targetCode = (string) ($attrs['target_account_code'] ?? ($targetAccount !== null ? $targetAccount->account_code : ''));
        $targetName = (string) ($attrs['target_account_name'] ?? ($targetAccount !== null ? $targetAccount->name : $targetCode));
        $isActive = (bool) ($attrs['is_active'] ?? true);
        $allocation = (float) ($attrs['allocation_percentage'] ?? 100.0);

        $duplicateQuery = AccountMapping::withoutGlobalScopes()
            ->where('tenant_id', $command->tenantId)
            ->where('source_account_id', $sourceAccount->id)
            ->where('target_standard', $targetStandard)
            ->where('target_account_code', $targetCode);

        if ($command->mappingId !== null) {
            $duplicateQuery->where('id', '!=', $command->mappingId);
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'target_account_code' => [__('general_ledger_errors.mapping.duplicate')],
            ]);
        }

        if ($isActive) {
            $existingAllocation = (float) AccountMapping::withoutGlobalScopes()
                ->where('tenant_id', $command->tenantId)
                ->where('source_account_id', $sourceAccount->id)
                ->where('target_standard', $targetStandard)
                ->where('is_active', true)
                ->when($command->mappingId !== null, static fn ($q) => $q->where('id', '!=', $command->mappingId))
                ->sum('allocation_percentage');

            if (($existingAllocation + $allocation) > 100.0001) {
                throw ValidationException::withMessages([
                    'allocation_percentage' => [__('general_ledger_errors.mapping.allocation_exceeded')],
                ]);
            }
        }

        $payload = [
            'company_id' => $command->companyId ?? $sourceAccount->company_id,
            'source_account_id' => $sourceAccount->id,
            'target_account_id' => $targetAccount?->id,
            'source_standard' => $sourceStandard,
            'target_standard' => $targetStandard,
            'target_account_code' => $targetCode,
            'target_account_name' => $targetName,
            'mapping_type' => (string) ($attrs['mapping_type'] ?? 'direct'),
            'is_bidirectional' => (bool) ($attrs['is_bidirectional'] ?? true),
            'allocation_percentage' => number_format($allocation, 4, '.', ''),
            'notes' => $attrs['notes'] ?? null,
            'is_active' => $isActive,
        ];

        if ($command->mappingId !== null) {
            /** @var AccountMapping $mapping */
            $mapping = AccountMapping::withoutGlobalScopes()
                ->where('tenant_id', $command->tenantId)
                ->findOrFail($command->mappingId);
            $mapping->update($payload);

            return $mapping->fresh(['sourceAccount', 'targetAccount']);
        }

        $mapping = AccountMapping::create(array_merge($payload, [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $command->tenantId,
        ]));

        return $mapping->load(['sourceAccount', 'targetAccount']);
    }
}
