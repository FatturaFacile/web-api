<?php

declare(strict_types=1);

namespace Modules\DownPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A credit note (TD04) was issued against an advance invoice and the chain reopened its residual.
 */
final readonly class AdvanceCreditNoteIssued
{
    use Dispatchable;

    public function __construct(
        public string $chainId,
        public string $linkId,
        public string $creditNoteId,
        public string $tenantId,
    ) {}
}
