<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Handlers;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Forecasting\Application\Commands\RunAiLiquiditySimulationCommand;
use Modules\Forecasting\Application\DTOs\WhatIfScenarioDto;
use Modules\Forecasting\Domain\Enums\ForecastHorizon;
use Modules\Forecasting\Domain\Exceptions\ForecastingException;
use Modules\Forecasting\Domain\Services\LiquidityProjectionService;
use Modules\Forecasting\Domain\Services\StatutoryCashOutflowCalendarService;
use Modules\Forecasting\Domain\ValueObjects\StatutoryCashEvent;
use Modules\Forecasting\Infrastructure\Models\InvoiceExpectedCollection;
use Modules\Forecasting\Infrastructure\Models\LiquidityForecastRun;
use Modules\Forecasting\Infrastructure\Models\PaymentBehaviorModel;
use Modules\Forecasting\Infrastructure\Persistence\ForecastingDataReader;
use Modules\Forecasting\Infrastructure\Services\ForecastingTenantRunner;
use Modules\Forecasting\Infrastructure\Services\StatutoryProfileResolver;
use Modules\Treasury\Models\RecurringCashItem;

/**
 * Combines the open receivables dated by the payment behaviour of their customers, the open supplier bills and recurring
 * items, and the statutory tax and payroll calendar of the tenant country into a daily P10/P50/P90 cash projection.
 */
final readonly class RunAiLiquiditySimulationHandler
{
    private const int DriverLimit = 5;

    public function __construct(
        private ForecastingDataReader $reader,
        private ForecastingTenantRunner $runner,
        private LiquidityProjectionService $projection,
        private StatutoryCashOutflowCalendarService $calendar,
        private StatutoryProfileResolver $profiles,
    ) {}

    /**
     * @throws ForecastingException
     */
    public function handle(RunAiLiquiditySimulationCommand $command): LiquidityForecastRun
    {
        try {
            ForecastHorizon::fromDays($command->horizonDays);
        } catch (InvalidArgumentException $exception) {
            throw new ForecastingException($exception->getMessage(), 0, $exception);
        }

        return $this->runner->run($command->tenantId, fn (Tenant $tenant): LiquidityForecastRun => $this->simulate($tenant, $command));
    }

    private function simulate(Tenant $tenant, RunAiLiquiditySimulationCommand $command): LiquidityForecastRun
    {
        $tenantId = (string) $tenant->id;
        $today = CarbonImmutable::today();
        $horizon = $command->horizonDays;
        $whatIf = $command->whatIf ?? new WhatIfScenarioDto;
        $currency = (string) ($tenant->default_currency ?: 'EUR');

        $expected = InvoiceExpectedCollection::query()->where('tenant_id', $tenantId)->get()->keyBy('invoice_id');
        $open = $this->reader->openReceivables($tenantId);
        $receivables = [];

        foreach ($open as $invoice) {
            $record = $expected->get($invoice['id']);
            $receivables[] = [
                'amount' => $invoice['open_amount'],
                'earliest' => $record !== null ? $record->earliest_collection_date->format('Y-m-d') : $invoice['due_date'],
                'expected' => $record !== null ? $record->expected_collection_date->format('Y-m-d') : $invoice['due_date'],
                'latest' => $record !== null ? $record->latest_collection_date->format('Y-m-d') : $invoice['due_date'],
            ];
        }

        $payables = array_map(static fn (array $bill): array => ['amount' => $bill['open_amount'], 'date' => $bill['due_date']], $this->reader->openPayables($tenantId));
        $recurring = $this->recurringOccurrences($today, $horizon);
        $statutory = $this->calendar->eventsBetween(
            $this->profiles->jurisdiction($tenant),
            $today->toDateTimeImmutable()->modify('+1 day'),
            $today->toDateTimeImmutable()->modify("+{$horizon} days"),
            $this->profiles->resolve($tenant, $today),
        );

        $startingCash = $this->reader->startingCash($tenantId, $currency);
        $buckets = $this->projection->project(
            $today->toDateTimeImmutable(),
            $horizon,
            $startingCash,
            $receivables,
            $payables,
            $recurring,
            $statutory,
            $whatIf->globalCustomerDelayShiftDays,
            $whatIf->revenueGrowthPercentage,
            $whatIf->supplierPaymentExtensionDays,
            $whatIf->interestRateDelta,
        );

        $run = new LiquidityForecastRun([
            'generated_at' => now(),
            'horizon_days' => $horizon,
            'currency' => $currency,
            'starting_cash_balance' => round($startingCash, 2),
            'daily_projections_json' => $buckets,
            'key_drivers_json' => $this->keyDrivers($tenantId, $open, $expected->all(), $statutory, $today),
            'what_if_parameters_json' => $whatIf->isNeutral() ? null : $whatIf->toArray(),
        ]);
        $run->tenant_id = $tenantId;

        if ($command->persist) {
            $run->save();
        }

        return $run;
    }

    /**
     * @return list<array{amount: float, date: string, direction: string}>
     */
    private function recurringOccurrences(CarbonImmutable $today, int $horizon): array
    {
        $items = [];

        foreach (RecurringCashItem::query()->where('is_active', true)->get() as $item) {
            foreach ($item->occurrencesBetween($today->addDay(), $today->addDays($horizon)) as $date) {
                $items[] = ['amount' => (float) $item->amount, 'date' => $date->toDateString(), 'direction' => (string) $item->direction];
            }
        }

        return $items;
    }

    /**
     * The overdue invoices, the biggest statutory outflows and the customers whose delay weighs most on the horizon.
     *
     * @param  list<array{id: string, customer_id: string, invoice_number: string, due_date: string, open_amount: float}>  $open
     * @param  array<int|string, InvoiceExpectedCollection>  $expected
     * @param  list<StatutoryCashEvent>  $statutory
     * @return array{overdue_invoices: list<array<string, mixed>>, statutory_liabilities: list<array<string, mixed>>, customer_delays: list<array<string, mixed>>}
     */
    private function keyDrivers(string $tenantId, array $open, array $expected, array $statutory, CarbonImmutable $today): array
    {
        $overdue = array_values(array_filter($open, static fn (array $invoice): bool => $invoice['due_date'] < $today->toDateString()));
        usort($overdue, static fn (array $a, array $b): int => $b['open_amount'] <=> $a['open_amount']);

        $liabilities = $statutory;
        usort($liabilities, static fn ($a, $b): int => $b->amount <=> $a->amount);

        $models = PaymentBehaviorModel::query()->where('tenant_id', $tenantId)->get()->keyBy('customer_id');
        $exposure = [];

        foreach ($open as $invoice) {
            $exposure[$invoice['customer_id']] = ($exposure[$invoice['customer_id']] ?? 0.0) + $invoice['open_amount'];
        }

        $delays = [];

        foreach ($exposure as $customerId => $amount) {
            $model = $models->get($customerId);
            $offset = $model !== null ? $model->predicted_collection_date_offset_days : 0;

            if ($offset > 0) {
                $delays[] = [
                    'customer_id' => (string) $customerId,
                    'open_amount' => round($amount, 2),
                    'predicted_delay_days' => $offset,
                    'risk_tier' => $model->payment_risk_tier->value,
                    'impact' => round($amount * $offset, 2),
                ];
            }
        }

        usort($delays, static fn (array $a, array $b): int => $b['impact'] <=> $a['impact']);
        $delays = array_slice($delays, 0, self::DriverLimit);
        $names = $this->reader->contactNames($tenantId, array_merge(array_column($delays, 'customer_id'), array_column(array_slice($overdue, 0, self::DriverLimit), 'customer_id')));

        return [
            'overdue_invoices' => array_map(static fn (array $invoice): array => [
                'invoice_id' => $invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'customer_id' => $invoice['customer_id'],
                'customer_name' => $names[$invoice['customer_id']] ?? null,
                'due_date' => $invoice['due_date'],
                'open_amount' => round($invoice['open_amount'], 2),
                'days_overdue' => (int) CarbonImmutable::parse($invoice['due_date'])->diffInDays($today),
            ], array_slice($overdue, 0, self::DriverLimit)),
            'statutory_liabilities' => array_map(static fn ($event): array => $event->toArray(), array_slice($liabilities, 0, self::DriverLimit)),
            'customer_delays' => array_map(static fn (array $delay): array => $delay + ['customer_name' => $names[$delay['customer_id']] ?? null], $delays),
        ];
    }
}
