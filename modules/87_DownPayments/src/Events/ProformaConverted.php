<?php

declare(strict_types=1);

namespace Modules\DownPayments\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A paid pro-forma advance request was converted into its advance invoice.
 */
final readonly class ProformaConverted
{
    use Dispatchable;

    public function __construct(
        public string $chainId,
        public string $proformaLinkId,
        public string $advanceLinkId,
        public string $tenantId,
    ) {}
}
