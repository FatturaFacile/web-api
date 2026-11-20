<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Handlers;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Forecasting\Application\Commands\TrainCustomerPaymentDelayModelCommand;
use Modules\Forecasting\Domain\Entities\CustomerPaymentBehaviorModel;
use Modules\Forecasting\Domain\Enums\PaymentRiskTier;
use Modules\Forecasting\Domain\Services\PaymentDelayStatistics;
use Modules\Forecasting\Infrastructure\Models\InvoiceExpectedCollection;
use Modules\Forecasting\Infrastructure\Models\PaymentBehaviorModel;
use Modules\Forecasting\Infrastructure\Persistence\ForecastingDataReader;
use Modules\Forecasting\Infrastructure\Services\ForecastingTenantRunner;

/**
 * Trains the payment behaviour of every customer with settled invoices from the exponentially weighted delay between
 * the due date and the last payment, then dates every open receivable with it (feeding the cash flow forecast, Mod 017).
 * A customer with no history takes the tenant-wide behaviour.
 */
final readonly class TrainCustomerPaymentDelayModelHandler
{
    public function __construct(
        private ForecastingDataReader $reader,
        private ForecastingTenantRunner $runner,
    ) {}

    /**
     * @return array{models_trained: int, invoices_updated: int}
     */
    public function handle(TrainCustomerPaymentDelayModelCommand $command): array
    {
        return $this->runner->run($command->tenantId, function () use ($command): array {
            $today = CarbonImmutable::today();
            $samples = [];
            $all = [];

            foreach ($this->reader->settledInvoices($command->tenantId, $today->subMonths($command->lookbackMonths)) as $row) {
                $due = CarbonImmutable::parse($row['due_date'])->startOfDay();
                $settled = CarbonImmutable::parse($row['settled_at'])->startOfDay();
                $sample = ['days_late' => (int) $due->diffInDays($settled, false), 'age_days' => max(0, (int) $settled->diffInDays($today, false))];
                $samples[$row['customer_id']][] = $sample;
                $all[] = $sample;
            }

            $now = new DateTimeImmutable;
            $models = [];

            DB::transaction(function () use ($command, $samples, $now, &$models): void {
                foreach ($samples as $customerId => $customerSamples) {
                    $models[(string) $customerId] = $this->store($command->tenantId, (string) $customerId, $customerSamples, $command->halfLifeDays, $now)->toDomain();
                }
            });

            $fallback = $this->domainModel($command->tenantId, '', PaymentDelayStatistics::compute($all, $command->halfLifeDays), $now);
            $updated = $this->dateOpenReceivables($command->tenantId, $models, $fallback, $now);

            return ['models_trained' => count($models), 'invoices_updated' => $updated];
        });
    }

    /**
     * @param  list<array{days_late: int, age_days: int}>  $samples
     */
    private function store(string $tenantId, string $customerId, array $samples, int $halfLife, DateTimeImmutable $now): PaymentBehaviorModel
    {
        $model = $this->domainModel($tenantId, $customerId, PaymentDelayStatistics::compute($samples, $halfLife), $now);

        $record = PaymentBehaviorModel::query()->firstOrNew(['tenant_id' => $tenantId, 'customer_id' => $customerId]);
        $record->fill([
            'sample_size' => $model->sampleSize,
            'average_days_late' => $model->averageDaysLate,
            'std_dev_days_late' => $model->stdDevDaysLate,
            'on_time_payment_probability' => $model->onTimePaymentProbability,
            'predicted_collection_date_offset_days' => $model->predictedCollectionDateOffsetDays,
            'payment_risk_tier' => $model->paymentRiskTier,
            'last_trained_at' => $now,
        ]);
        $record->tenant_id = $tenantId;
        $record->save();

        return $record;
    }

    /**
     * @param  array{mean: float, std_dev: float, on_time_probability: float, count: int}  $statistics
     */
    private function domainModel(string $tenantId, string $customerId, array $statistics, DateTimeImmutable $now): CustomerPaymentBehaviorModel
    {
        return new CustomerPaymentBehaviorModel(
            '',
            $tenantId,
            $customerId,
            $statistics['count'],
            round($statistics['mean'], 2),
            round($statistics['std_dev'], 2),
            round($statistics['on_time_probability'], 4),
            (int) round($statistics['mean']),
            PaymentRiskTier::classify($statistics['mean'], $statistics['on_time_probability']),
            $now,
        );
    }

    /**
     * @param  array<string, CustomerPaymentBehaviorModel>  $models
     */
    private function dateOpenReceivables(string $tenantId, array $models, CustomerPaymentBehaviorModel $fallback, DateTimeImmutable $now): int
    {
        $updated = 0;
        $openIds = [];

        DB::transaction(function () use ($tenantId, $models, $fallback, $now, &$updated, &$openIds): void {
            foreach ($this->reader->openReceivables($tenantId) as $invoice) {
                $model = $models[$invoice['customer_id']] ?? $fallback;
                $window = $model->calculateInvoiceConfidenceWindow(new DateTimeImmutable($invoice['due_date']));

                $record = InvoiceExpectedCollection::query()->firstOrNew(['tenant_id' => $tenantId, 'invoice_id' => $invoice['id']]);
                $record->fill([
                    'customer_id' => $invoice['customer_id'],
                    'due_date' => $invoice['due_date'],
                    'expected_collection_date' => $window['p50']->format('Y-m-d'),
                    'earliest_collection_date' => $window['p10']->format('Y-m-d'),
                    'latest_collection_date' => $window['p90']->format('Y-m-d'),
                    'open_amount' => round($invoice['open_amount'], 2),
                    'calculated_at' => $now,
                ]);
                $record->tenant_id = $tenantId;
                $record->save();

                $openIds[] = $invoice['id'];
                $updated++;
            }

            // Receivables collected since the last training no longer need a date.
            InvoiceExpectedCollection::query()->where('tenant_id', $tenantId)->whereNotIn('invoice_id', $openIds)->delete();
        });

        return $updated;
    }
}
