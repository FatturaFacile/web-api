<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Queries;

use App\Models\Invoice;
use Illuminate\Support\Collection;
use Modules\SwissEbill\Application\Services\EbillPreflightService;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Models\EbillTransmission;

/**
 * Everything the invoice screen needs about eBill: the transmissions with their timeline, newest first, and the pre-flight check.
 */
final class GetInvoiceEbillHandler
{
    public function __construct(
        private readonly EbillPreflightService $preflight,
        private readonly EbillSettingRepositoryInterface $settings,
    ) {}

    /**
     * @return array{transmissions: Collection<int, EbillTransmission>, violations: list<array{code: string, field: string}>}
     */
    public function handle(string $invoiceId, bool $withPreflight = true): array
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);

        return [
            'transmissions' => EbillTransmission::query()->where('invoice_id', $invoice->id)->with('events')->latest('created_at')->latest('id')->get(),
            'violations' => $withPreflight ? $this->preflight->violations($invoice, $this->settings->current()) : [],
        ];
    }
}
