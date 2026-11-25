<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\ChartOfAccountService;

final readonly class GetAccountDetailHandler
{
    public function __construct(
        private ChartOfAccountService $service,
    ) {}

    /**
     * @return array{
     *     account: ChartOfAccount,
     *     breadcrumbs: list<array{id: string, account_code: string, account_name: string}>,
     *     has_postings: bool,
     * }
     */
    public function handle(GetAccountDetailQuery $query): array
    {
        /** @var ChartOfAccount $account */
        $account = ChartOfAccount::query()
            ->where('tenant_id', $query->tenantId)
            ->with([
                'parentAccount',
                'subAccounts',
                'vatCode',
                'defaultCostCenter',
                'outgoingMappings.targetAccount',
                'incomingMappings.sourceAccount',
            ])
            ->findOrFail($query->accountId);

        $breadcrumbs = [];
        $cursor = $account;
        $visited = [];

        while ($cursor !== null && ! isset($visited[$cursor->id])) {
            $visited[$cursor->id] = true;
            array_unshift($breadcrumbs, [
                'id' => (string) $cursor->id,
                'account_code' => $cursor->account_code,
                'account_name' => $cursor->name,
            ]);
            $cursor = $cursor->parent_account_id !== null
                ? ChartOfAccount::query()->where('tenant_id', $query->tenantId)->find($cursor->parent_account_id)
                : null;
        }

        return [
            'account' => $account,
            'breadcrumbs' => $breadcrumbs,
            'has_postings' => $this->service->hasPostings($account),
        ];
    }
}
