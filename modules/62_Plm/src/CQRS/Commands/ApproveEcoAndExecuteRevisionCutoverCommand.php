<?php

declare(strict_types=1);

namespace Modules\Plm\CQRS\Commands;

/**
 * Verifies required approvals across Engineering, Quality, Purchasing, and Production,
 * releases the new BOM/routing revision (Mod 059), applies stock disposition rules (Mod 060),
 * updates MES shop-floor drawing watermarks (Mod 061), and records ECN release metadata.
 */
final readonly class ApproveEcoAndExecuteRevisionCutoverCommand
{
    public function __construct(
        public string $tenantId,
        public string $changeOrderId,
        public ?string $actorUserId = null,
        public bool $forceImmediateSafetyCutover = false,
        public ?string $lotSerialCutoverCode = null,
    ) {}
}
