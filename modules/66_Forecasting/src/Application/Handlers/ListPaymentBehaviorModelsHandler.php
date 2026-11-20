<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\Forecasting\Infrastructure\Models\InvoiceExpectedCollection;
use Modules\Forecasting\Infrastructure\Models\PaymentBehaviorModel;
use Modules\Forecasting\Infrastructure\Persistence\ForecastingDataReader;
use Modules\Forecasting\Infrastructure\Services\ForecastingTenantRunner;

/**
 * The payment behaviour of the customers with the predicted collection dates of their open invoices, worst first.
 */
final readonly class ListPaymentBehaviorModelsHandler
{
    public function __construct(
        private ForecastingDataReader $reader,
        private ForecastingTenantRunner $runner,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(string $tenantId): array
    {
        return $this->runner->run($tenantId, function () use ($tenantId): array {
            $models = PaymentBehaviorModel::query()->where('tenant_id', $tenantId)->get();
            $invoices = InvoiceExpectedCollection::query()->where('tenant_id', $tenantId)->orderBy('expected_collection_date')->get()->groupBy('customer_id');
            $numbers = $this->invoiceNumbers($tenantId, $invoices->flatten()->pluck('invoice_id')->all());
            $names = $this->reader->contactNames($tenantId, $models->pluck('customer_id')->all());

            return $models
                ->sortByDesc(fn (PaymentBehaviorModel $model): int => $model->payment_risk_tier->severity() * 1000 + $model->predicted_collection_date_offset_days)
                ->map(fn (PaymentBehaviorModel $model): array => [
                    'id' => $model->id,
                    'customer_id' => $model->customer_id,
                    'customer_name' => $names[$model->customer_id] ?? null,
                    'sample_size' => $model->sample_size,
                    'average_days_late' => (float) $model->average_days_late,
                    'std_dev_days_late' => (float) $model->std_dev_days_late,
                    'on_time_payment_probability' => (float) $model->on_time_payment_probability,
                    'predicted_collection_date_offset_days' => $model->predicted_collection_date_offset_days,
                    'payment_risk_tier' => $model->payment_risk_tier->value,
                    'last_trained_at' => $model->last_trained_at?->toIso8601String(),
                    'open_invoices' => ($invoices->get($model->customer_id) ?? collect())->map(fn (InvoiceExpectedCollection $invoice): array => [
                        'invoice_id' => $invoice->invoice_id,
                        'invoice_number' => $numbers[$invoice->invoice_id] ?? null,
                        'due_date' => $invoice->due_date->toDateString(),
                        'expected_collection_date' => $invoice->expected_collection_date->toDateString(),
                        'open_amount' => (float) $invoice->open_amount,
                    ])->values()->all(),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function invoiceNumbers(string $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('invoices')->where('tenant_id', $tenantId)->whereIn('id', $ids)->pluck('invoice_number', 'id')->map(static fn ($number): string => (string) $number)->all();
    }
}
