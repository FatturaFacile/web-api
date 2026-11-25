<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

use Illuminate\Support\Collection;
use Modules\GeneralLedger\Models\AccountMapping;

final readonly class GetAccountMappingsHandler
{
    /**
     * Returns flat mappings, bidirectional reverse-navigable view, and a tree grouped by source_standard -> source_account -> targets.
     *
     * @return array{
     *     mappings: Collection<int, AccountMapping>,
     *     incoming: Collection<int, AccountMapping>,
     *     tree: list<array<string, mixed>>,
     * }
     */
    public function handle(GetAccountMappingsQuery $query): array
    {
        $mappings = AccountMapping::query()
            ->where('tenant_id', $query->tenantId)
            ->with(['sourceAccount', 'targetAccount'])
            ->when($query->sourceAccountId !== null && $query->sourceAccountId !== '', static fn ($q) => $q->where('source_account_id', $query->sourceAccountId))
            ->when($query->sourceStandard !== null && $query->sourceStandard !== '', static fn ($q) => $q->where('source_standard', $query->sourceStandard))
            ->when($query->targetStandard !== null && $query->targetStandard !== '', static fn ($q) => $q->where('target_standard', $query->targetStandard))
            ->when($query->isActive !== null, static fn ($q) => $q->where('is_active', $query->isActive))
            ->orderBy('source_standard')
            ->orderBy('target_standard')
            ->orderBy('target_account_code')
            ->get();

        $incoming = collect();
        if ($query->sourceAccountId !== null && $query->sourceAccountId !== '') {
            $incoming = AccountMapping::query()
                ->where('tenant_id', $query->tenantId)
                ->with(['sourceAccount', 'targetAccount'])
                ->where('target_account_id', $query->sourceAccountId)
                ->where('is_bidirectional', true)
                ->orderBy('source_standard')
                ->get();
        }

        // Build mapping tree grouped by source_standard -> source_account_id
        $byStandard = [];
        foreach ($mappings as $mapping) {
            $std = $mapping->source_standard;
            $srcId = (string) $mapping->source_account_id;

            if (! isset($byStandard[$std])) {
                $byStandard[$std] = [
                    'standard' => $std,
                    'accounts' => [],
                ];
            }

            if (! isset($byStandard[$std]['accounts'][$srcId])) {
                $sourceAccount = $mapping->sourceAccount;
                $byStandard[$std]['accounts'][$srcId] = [
                    'source_account_id' => $srcId,
                    'source_account_code' => $sourceAccount !== null ? $sourceAccount->account_code : '',
                    'source_account_name' => $sourceAccount !== null ? $sourceAccount->name : '',
                    'targets' => [],
                ];
            }

            $byStandard[$std]['accounts'][$srcId]['targets'][] = [
                'id' => $mapping->id,
                'target_account_id' => $mapping->target_account_id,
                'target_standard' => $mapping->target_standard,
                'target_account_code' => $mapping->target_account_code,
                'target_account_name' => $mapping->target_account_name,
                'mapping_type' => $mapping->mapping_type,
                'is_bidirectional' => (bool) $mapping->is_bidirectional,
                'allocation_percentage' => (float) $mapping->allocation_percentage,
                'is_active' => (bool) $mapping->is_active,
            ];
        }

        $tree = array_values(array_map(static function (array $stdGroup): array {
            $stdGroup['accounts'] = array_values($stdGroup['accounts']);

            return $stdGroup;
        }, $byStandard));

        return [
            'mappings' => $mappings,
            'incoming' => $incoming,
            'tree' => $tree,
        ];
    }
}
