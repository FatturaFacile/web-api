<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\User;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationService;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Intercompany\Support\GroupScope;

/**
 * The two ownership schedules of the consolidated package, read from the snapshot of a run: the schedule of shareholdings
 * and scope of consolidation (prospetto delle partecipazioni e area di consolidamento) and the schedule of non-controlling
 * interests (prospetto delle quote di terzi). Direct, effective (through the holders) and consolidation shares come from the
 * run, which computed them from the group tree; no voting rights are stored in the group, so the voting share is the share
 * of capital held directly.
 */
final readonly class ConsolidationSchedulesService
{
    public function __construct(
        private ConsolidationService $consolidation,
        private GroupScope $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function shareholdings(User $user, ConsolidationRun $run): array
    {
        $this->consolidation->assertCanReadRun($user, $run);

        $members = $run->members_snapshot;
        $names = $this->scope->names(array_map(static fn (array $member): string => (string) $member['tenant_id'], $members));
        $rows = [];

        foreach ($members as $member) {
            $tenantId = (string) $member['tenant_id'];
            $rows[] = [
                'member_tenant_id' => $tenantId,
                'name' => (string) ($member['name'] ?? $names[$tenantId] ?? $tenantId),
                'holder_tenant_id' => $member['holder_tenant_id'] ?? null,
                'direct_percentage' => ConsolidationMath::norm((string) $member['ownership_percentage']),
                'effective_percentage' => ConsolidationMath::norm((string) ($member['effective_ownership'] ?? $member['ownership_percentage'])),
                'voting_percentage' => ConsolidationMath::norm((string) $member['ownership_percentage']),
                'consolidation_method' => (string) $member['consolidation_method'],
                'consolidation_share' => ConsolidationMath::norm((string) ($member['consolidation_share'] ?? '100')),
                'currency' => TenantAccountingCurrency::of($tenantId),
                'consolidation_basis' => (string) ($member['consolidation_basis'] ?? 'own_year'),
                'is_parent' => (bool) ($member['is_parent'] ?? false),
            ];
        }

        return ['run_id' => (string) $run->id, 'currency' => (string) $run->group_currency, 'rows' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function nci(User $user, ConsolidationRun $run): array
    {
        $this->consolidation->assertCanReadRun($user, $run);

        $snapshot = [];

        foreach ($run->members_snapshot as $member) {
            $snapshot[(string) $member['tenant_id']] = $member;
        }

        $names = $this->scope->names(array_keys($snapshot));
        $rows = [];
        $totalAssets = $totalProfit = ConsolidationMath::zero();

        foreach (($run->totals['non_controlling_interests'] ?? []) as $nci) {
            $tenantId = (string) $nci['member_tenant_id'];
            $member = $snapshot[$tenantId] ?? [];
            $assets = ConsolidationMath::norm((string) $nci['nci_net_assets']);
            $profit = ConsolidationMath::norm((string) $nci['nci_profit']);
            $share = (string) ($member['effective_ownership'] ?? $nci['ownership_percentage'] ?? $member['ownership_percentage'] ?? '100');

            $rows[] = [
                'member_tenant_id' => $tenantId,
                'name' => (string) ($member['name'] ?? $names[$tenantId] ?? $tenantId),
                'ownership_percentage' => ConsolidationMath::norm((string) ($nci['ownership_percentage'] ?? $member['ownership_percentage'] ?? '100')),
                'nci_percentage' => ConsolidationMath::sub('100', $share),
                'consolidation_method' => (string) ($member['consolidation_method'] ?? 'full'),
                'net_assets' => ConsolidationMath::norm((string) ($nci['net_assets'] ?? '0')),
                'nci_net_assets' => $assets,
                'nci_profit' => $profit,
                'nci_opening' => ConsolidationMath::sub($assets, $profit),
                'nci_movement' => $profit,
            ];
            $totalAssets = ConsolidationMath::add($totalAssets, $assets);
            $totalProfit = ConsolidationMath::add($totalProfit, $profit);
        }

        return [
            'run_id' => (string) $run->id,
            'currency' => (string) $run->group_currency,
            'rows' => $rows,
            'total_nci_net_assets' => $totalAssets,
            'total_nci_profit' => $totalProfit,
        ];
    }
}
