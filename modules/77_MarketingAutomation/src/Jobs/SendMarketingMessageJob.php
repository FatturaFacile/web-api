<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\MarketingAutomation\Models\MarketingMessage;
use Modules\MarketingAutomation\Services\MarketingMessageService;

/**
 * Sends one queued promotional message (e-mail through the provider adapter, WhatsApp / SMS through Communication).
 */
final class SendMarketingMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<string, mixed>|null  $stepConfig
     */
    public function __construct(public readonly string $messageId, public readonly ?array $stepConfig = null) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(MarketingMessageService $service, TenantContext $context): void
    {
        $message = MarketingMessage::withoutGlobalScopes()->find($this->messageId);
        if ($message === null) {
            return;
        }

        $tenant = Tenant::query()->findOrFail($message->tenant_id);
        $context->runWithTenant($tenant, fn () => $service->deliver($message, $this->stepConfig));
    }
}
