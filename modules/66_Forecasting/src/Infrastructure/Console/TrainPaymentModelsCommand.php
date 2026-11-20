<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Console;

use Modules\Forecasting\Application\Commands\TrainCustomerPaymentDelayModelCommand;
use Modules\Forecasting\Application\Handlers\TrainCustomerPaymentDelayModelHandler;

/**
 * Nightly: retrains the customer payment behaviour and redates the open receivables.
 */
final class TrainPaymentModelsCommand extends ForecastingTenantCommand
{
    protected $signature = 'forecasting:train-payment-models {--tenant= : Only this tenant id}';

    protected $description = 'Train the customer payment delay models and date the open receivables';

    public function handle(TrainCustomerPaymentDelayModelHandler $handler): int
    {
        return $this->forEachTenant(static function (string $tenantId) use ($handler): string {
            $result = $handler->handle(new TrainCustomerPaymentDelayModelCommand($tenantId));

            return "{$result['models_trained']} models, {$result['invoices_updated']} open invoices dated";
        });
    }
}
