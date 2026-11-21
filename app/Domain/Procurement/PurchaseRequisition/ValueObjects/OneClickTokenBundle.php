<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\ValueObjects;

/**
 * Cryptographic single-use approve/reject token bundles issued for email and push 1-click actions.
 */
final readonly class OneClickTokenBundle
{
    public function __construct(
        public string $approverUserId,
        public string $approveToken,
        public string $rejectToken,
        public string $approveUrl,
        public string $rejectUrl,
        public string $expiresAtIso,
        public string $pushApproveToken = '',
        public string $pushRejectToken = '',
    ) {}
}
