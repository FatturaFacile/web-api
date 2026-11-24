<?php

declare(strict_types=1);

namespace Modules\Integrations\Console;

use Illuminate\Console\Command;
use Modules\Integrations\Webhooks\WebhookDeliveryService;

final class DispatchOverdueWebhooksCommand extends Command
{
    protected $signature = 'webhooks:dispatch-overdue';

    protected $description = 'Queue again the webhook deliveries that are due but have no live job (run every minute by the scheduler)';

    public function handle(WebhookDeliveryService $deliveries): int
    {
        $queued = $deliveries->dispatchOverdue();

        $this->info("Queued {$queued} overdue webhook deliveries.");

        return self::SUCCESS;
    }
}
