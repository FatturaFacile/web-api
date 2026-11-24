<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecurringContracts\PeriodicBillingBatchProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ProcessRecurringBillingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:process-recurring
                            {--date= : Cutoff date for billing (defaults to today, format YYYY-MM-DD)}
                            {--tenant= : Optional specific tenant UUID to process}
                            {--dry-run : Preview due contracts and amounts without creating invoices}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process recurring billing batches for all active contracts due up to cutoff date';

    /**
     * Execute the console command.
     */
    public function handle(PeriodicBillingBatchProcessor $batchProcessor): int
    {
        $dateStr = $this->option('date');
        $cutoffDate = $dateStr ? CarbonImmutable::parse($dateStr) : CarbonImmutable::today();
        $tenantId = $this->option('tenant');
        $isDryRun = (bool) $this->option('dry-run');

        $this->info("Processing recurring billing for cutoff date: {$cutoffDate->toDateString()}");
        if ($tenantId) {
            $this->info("Scoped to tenant: {$tenantId}");
        }

        if ($isDryRun) {
            $this->warn('DRY-RUN MODE ENABLED - No database modifications will occur.');
            $preview = $batchProcessor->previewDueContracts($cutoffDate, $tenantId);

            if (empty($preview)) {
                $this->info("No contracts due for billing up to {$cutoffDate->toDateString()}.");

                return self::SUCCESS;
            }

            $tableRows = array_map(function ($item) {
                return [
                    $item['contract_number'],
                    $item['customer_name'],
                    $item['currency'],
                    $item['period_start'].' to '.$item['period_end'],
                    $item['total'],
                    $item['is_already_billed'] ? 'ALREADY BILLED (SKIP)' : 'DUE',
                ];
            }, $preview);

            $this->table(['Contract #', 'Customer', 'Currency', 'Period', 'Total', 'Status'], $tableRows);
            $this->info('Total contracts evaluated: '.count($preview));

            return self::SUCCESS;
        }

        $run = $batchProcessor->executeBatchRun($cutoffDate, 'scheduled', $tenantId);

        $this->info("Billing Run [{$run->id}] finished with status: {$run->status}");
        $this->line(" - Contracts processed: {$run->contracts_processed}");
        $this->line(" - Invoices generated: {$run->invoices_generated}");
        $this->line(" - Total batch amount: {$run->total_amount}");

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
