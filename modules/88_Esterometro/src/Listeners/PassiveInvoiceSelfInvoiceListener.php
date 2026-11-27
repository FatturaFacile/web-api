<?php

declare(strict_types=1);

namespace Modules\Esterometro\Listeners;

use App\Models\PassiveInvoice;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Esterometro\Models\SelfInvoiceSetting;
use Modules\Esterometro\Services\SelfInvoiceClassifierService;
use Modules\Esterometro\Services\SelfInvoiceGeneratorService;
use Modules\GeneralLedger\Events\PassiveInvoicePostedEvent;
use Throwable;

/**
 * Automatically generates a draft/validated TD16–TD28 self-invoice when an Italian tenant posts a qualifying purchase invoice.
 */
final class PassiveInvoiceSelfInvoiceListener implements ShouldQueue
{
    public function __construct(
        private readonly SelfInvoiceClassifierService $classifier,
        private readonly SelfInvoiceGeneratorService $generator,
    ) {}

    public function handle(PassiveInvoicePostedEvent $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);
        if ($tenant === null) {
            return;
        }

        $tenantCountry = strtoupper(trim((string) ($tenant->country_code ?? 'IT')));
        if ($tenantCountry !== 'IT') {
            return;
        }

        $settings = SelfInvoiceSetting::query()->where('tenant_id', $event->tenantId)->first();
        if ($settings !== null && $settings->auto_generate_mode === 'manual') {
            return;
        }

        $invoice = PassiveInvoice::query()
            ->where('tenant_id', $event->tenantId)
            ->find($event->invoiceId);

        if ($invoice === null) {
            return;
        }

        $classification = $this->classifier->classifyPassiveInvoice($invoice);
        if (! $classification['requires_self_invoice']) {
            return;
        }

        try {
            $this->generator->generateFromPassiveInvoice($invoice, null, [
                'validate_and_reconcile' => $settings?->auto_generate_mode === 'auto_validate',
            ]);
        } catch (Throwable) {
            // Duplicate or incomplete fiscal metadata must not roll back the passive invoice posting.
        }
    }
}
