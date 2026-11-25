<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Domain\Enums\WelfareProvider;

/**
 * Reconciles the vouchers of a provider against its settlement statement or self-invoice.
 */
final readonly class ReconcileWelfareVoucherBatchCommand
{
    public function __construct(
        public WelfareProvider $provider,
        public string $batchReference,
        public ?string $selfInvoiceNumber,
        /** @var list<array<string, mixed>> */
        public array $lines,
        public ?string $userId = null,
    ) {}
}
