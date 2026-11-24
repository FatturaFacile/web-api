<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Modules\GeneralLedger\Services\SplitPaymentReceivableAdjuster;

/**
 * Lists, and with --apply corrects, the split-payment invoices issued before the posting left only the net on the
 * receivable. Without --apply nothing is written. Each invoice gets at most one adjusting entry, so running it again
 * changes nothing; an invoice that cannot be adjusted is reported and the others go on.
 */
final class AdjustSplitPaymentReceivablesCommand extends Command
{
    protected $signature = 'general-ledger:adjust-split-payment-receivables
        {--tenant=* : Limit to these tenants}
        {--apply : Post the adjusting entries instead of only listing them}
        {--date= : Posting date of the adjustments (Y-m-d), today by default}';

    protected $description = 'Takes the VAT off the receivable of split-payment invoices posted with the gross (dry run unless --apply)';

    public function handle(TenantContext $tenantContext, SplitPaymentReceivableAdjuster $adjuster): int
    {
        $apply = (bool) $this->option('apply');
        $date = CarbonImmutable::parse((string) ($this->option('date') ?: now()->toDateString()));
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))->orderBy('name')->get();
        $failures = 0;

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $adjuster, $apply, $date, &$failures): void {
                $rows = $adjuster->affected((string) $tenant->id);

                if ($rows->isEmpty()) {
                    return;
                }

                $this->info("{$tenant->name} ({$tenant->id}): {$rows->count()} invoice(s)");
                $this->table(['Invoice', 'Date', 'Receivable posted', 'Amount due', 'Adjustment'], $rows->map(static fn (array $row): array => [
                    $row['invoice']->invoice_number,
                    CarbonImmutable::parse($row['invoice']->date)->toDateString(),
                    $row['receivable'],
                    $row['due'],
                    $row['adjustment'],
                ])->all());

                if (! $apply) {
                    return;
                }

                foreach ($rows as $row) {
                    try {
                        $adjuster->adjust($row['invoice'], $date);
                    } catch (InvalidArgumentException $e) {
                        $failures++;
                        $this->error("{$row['invoice']->invoice_number}: {$e->getMessage()}");
                    }
                }
            });
        }

        if (! $apply) {
            $this->comment('Dry run: nothing was posted. Run again with --apply to post the adjustments.');
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
