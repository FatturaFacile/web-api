<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Application\ChartOfAccounts\Queries;

use Modules\GeneralLedger\Models\AccountMapping;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\ChartOfAccountsExporter;

final readonly class ExportChartOfAccountsHandler
{
    public function __construct(
        private ChartOfAccountsExporter $exporter,
    ) {}

    /**
     * @return array{content: string, mime: string, filename: string}
     */
    public function handle(ExportChartOfAccountsQuery $query): array
    {
        $accounts = ChartOfAccount::query()
            ->where('tenant_id', $query->tenantId)
            ->with(['parentAccount', 'vatCode', 'defaultCostCenter', 'outgoingMappings'])
            ->when($query->templateCode !== null && $query->templateCode !== '', static fn ($q) => $q->where('template_code', $query->templateCode))
            ->when($query->isActive !== null, static fn ($q) => $q->where('is_active', $query->isActive))
            ->orderBy('account_code')
            ->get();

        $accountIds = $accounts->pluck('id')->all();

        $mappings = AccountMapping::query()
            ->where('tenant_id', $query->tenantId)
            ->with(['sourceAccount', 'targetAccount'])
            ->when($accountIds !== [], static fn ($q) => $q->whereIn('source_account_id', $accountIds))
            ->orderBy('source_standard')
            ->orderBy('target_account_code')
            ->get();

        $dateSuffix = now()->format('Y-m-d');
        $format = strtolower($query->format);

        return match ($format) {
            'csv' => [
                'content' => $this->exporter->toCsv($accounts),
                'mime' => 'text/csv; charset=UTF-8',
                'filename' => "chart-of-accounts-{$dateSuffix}.csv",
            ],
            'xml' => [
                'content' => $this->exporter->toXml($accounts, $mappings, $query->tenantId, $query->templateCode),
                'mime' => 'application/xml; charset=UTF-8',
                'filename' => "chart-of-accounts-{$dateSuffix}.xml",
            ],
            default => [
                'content' => $this->exporter->toXlsx($accounts, $mappings),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'filename' => "chart-of-accounts-{$dateSuffix}.xlsx",
            ],
        };
    }
}
