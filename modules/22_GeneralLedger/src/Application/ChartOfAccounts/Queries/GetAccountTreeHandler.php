<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

use Database\Seeders\ChartOfAccountTemplateSeeder;
use Modules\GeneralLedger\Models\ChartOfAccount;

final readonly class GetAccountTreeHandler
{
    /**
     * Build an O(N) unlimited-depth hierarchical tree plus backend-seeded UI copy and standards metadata.
     *
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{
     *         total_accounts: int,
     *         postable_accounts: int,
     *         active_accounts: int,
     *         max_depth: int,
     *         standards: list<string>,
     *         ui_copy: array<string, string>,
     *     },
     * }
     */
    public function handle(GetAccountTreeQuery $query): array
    {
        $accounts = ChartOfAccount::query()
            ->where('tenant_id', $query->tenantId)
            ->with(['vatCode', 'defaultCostCenter'])
            ->withCount(['outgoingMappings', 'incomingMappings', 'journalLines'])
            ->when($query->templateCode !== null && $query->templateCode !== '', static fn ($q) => $q->where('template_code', $query->templateCode))
            ->when($query->accountType !== null && $query->accountType !== '', static fn ($q) => $q->where('account_type', $query->accountType))
            ->when($query->isActive !== null, static fn ($q) => $q->where('is_active', $query->isActive))
            ->orderBy('account_code')
            ->get();

        $nodesById = [];
        $childrenByParent = [];
        $standards = [];
        $maxDepth = 0;
        $postableCount = 0;
        $activeCount = 0;

        foreach ($accounts as $account) {
            if ($account->template_code !== null && $account->template_code !== '') {
                $standards[$account->template_code] = true;
            }
            if ($account->is_postable) {
                $postableCount++;
            }
            if ($account->is_active) {
                $activeCount++;
            }

            $level = max(1, (int) ($account->level ?? 1));
            $maxDepth = max($maxDepth, $level);

            $nodesById[(string) $account->id] = [
                'id' => $account->id,
                'account_code' => $account->account_code,
                'account_name' => $account->name,
                'description' => $account->description,
                'account_type' => $account->account_type,
                'normal_balance' => $account->normal_balance,
                'parent_account_id' => $account->parent_account_id,
                'level' => $level,
                'hierarchy_path' => $account->hierarchy_path ?? $account->account_code,
                'vat_code_id' => $account->vat_code_id,
                'vat_code' => $account->vatCode !== null ? $account->vatCode->code : null,
                'default_cost_center_id' => $account->default_cost_center_id,
                'default_cost_center_code' => $account->defaultCostCenter !== null ? $account->defaultCostCenter->code : null,
                'open_items_enabled' => (bool) $account->open_items_enabled,
                'allowed_currencies' => $account->allowed_currencies ?? [],
                'is_postable' => (bool) $account->is_postable,
                'allows_manual_posting' => (bool) $account->allows_manual_posting,
                'is_system' => (bool) $account->is_system,
                'is_active' => (bool) $account->is_active,
                'template_code' => $account->template_code,
                'statutory_code' => $account->statutory_code,
                'reporting_class' => $account->reporting_class,
                'reconciliation_type' => $account->reconciliation_type,
                'outgoing_mappings_count' => (int) ($account->outgoing_mappings_count ?? 0),
                'incoming_mappings_count' => (int) ($account->incoming_mappings_count ?? 0),
                'has_postings' => ((int) ($account->journal_lines_count ?? 0)) > 0,
                'children' => [],
            ];

            $parentId = $account->parent_account_id !== null ? (string) $account->parent_account_id : '__root__';
            $childrenByParent[$parentId][] = (string) $account->id;
        }

        $search = $query->search !== null ? trim(mb_strtolower($query->search)) : '';

        $buildTree = static function (string $parentId, int $depth = 1) use (&$buildTree, &$nodesById, $childrenByParent, $search, &$maxDepth): array {
            $branch = [];

            foreach ($childrenByParent[$parentId] ?? [] as $childId) {
                $node = $nodesById[$childId];
                $node['level'] = $depth;
                $maxDepth = max($maxDepth, $depth);
                $node['children'] = $buildTree($childId, $depth + 1);

                if ($search !== '') {
                    $matchesSelf = str_contains(mb_strtolower((string) $node['account_code']), $search)
                        || str_contains(mb_strtolower((string) $node['account_name']), $search)
                        || str_contains(mb_strtolower((string) ($node['statutory_code'] ?? '')), $search);

                    if (! $matchesSelf && $node['children'] === []) {
                        continue;
                    }
                }

                $branch[] = $node;
            }

            return $branch;
        };

        $roots = $buildTree('__root__');

        // Also attach orphan nodes whose parent wasn't in the filtered set as top-level nodes
        foreach ($childrenByParent as $parentId => $childIds) {
            if ($parentId === '__root__' || isset($nodesById[$parentId])) {
                continue;
            }
            foreach ($buildTree($parentId) as $orphanRoot) {
                $roots[] = $orphanRoot;
            }
        }

        return [
            'data' => $roots,
            'meta' => [
                'total_accounts' => $accounts->count(),
                'postable_accounts' => $postableCount,
                'active_accounts' => $activeCount,
                'max_depth' => $maxDepth,
                'standards' => array_keys($standards),
                'ui_copy' => ChartOfAccountTemplateSeeder::localizedUiCopy($query->locale),
            ],
        ];
    }
}
