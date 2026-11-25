<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\GeneralLedger\Models\ChartOfAccount;

final readonly class ListAccountsHandler
{
    /**
     * @return LengthAwarePaginator<int, ChartOfAccount>
     */
    public function handle(ListAccountsQuery $query): LengthAwarePaginator
    {
        return ChartOfAccount::query()
            ->where('tenant_id', $query->tenantId)
            ->with(['parentAccount', 'vatCode', 'defaultCostCenter'])
            ->withCount(['subAccounts', 'outgoingMappings', 'incomingMappings'])
            ->when($query->accountType !== null && $query->accountType !== '', static fn ($q) => $q->where('account_type', $query->accountType))
            ->when($query->isActive !== null, static fn ($q) => $q->where('is_active', $query->isActive))
            ->when($query->isPostable !== null, static fn ($q) => $q->where('is_postable', $query->isPostable))
            ->when($query->templateCode !== null && $query->templateCode !== '', static fn ($q) => $q->where('template_code', $query->templateCode))
            ->when($query->search !== null && $query->search !== '', static function ($q) use ($query): void {
                $term = '%'.mb_strtolower($query->search).'%';
                $q->where(static function ($sub) use ($term): void {
                    $sub->whereRaw('LOWER(account_code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(statutory_code, \'\')) LIKE ?', [$term]);
                });
            })
            ->orderBy('account_code')
            ->paginate(max(1, min(500, $query->perPage)));
    }
}
