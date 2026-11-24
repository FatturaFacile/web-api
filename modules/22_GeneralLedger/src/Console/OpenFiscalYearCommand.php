<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Console;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;

final class OpenFiscalYearCommand extends Command
{
    protected $signature = 'general-ledger:open-fiscal-year {year : Calendar year} {--tenant=* : Limit to these tenants}';

    protected $description = 'Opens a calendar fiscal year with its monthly periods: documents cannot be issued without an open period';

    public function handle(OpenFiscalYearAction $openFiscalYear): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))->pluck('id');

        foreach ($tenants as $tenantId) {
            $openFiscalYear->execute((string) $tenantId, (int) $this->argument('year'));
        }

        $this->info("Fiscal year {$this->argument('year')} open for {$tenants->count()} tenants.");

        return self::SUCCESS;
    }
}
