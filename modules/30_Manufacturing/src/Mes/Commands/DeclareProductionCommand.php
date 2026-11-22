<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Mes\Commands;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Enums\DefectReason;

/**
 * Declares good pieces (Italian: pezzi buoni) and scrapped pieces (pezzi scartati) of a phase; scrap needs its defect
 * reason.
 */
final readonly class DeclareProductionCommand extends PhaseCommand
{
    public function __construct(
        string $tenantId,
        ?string $actorUserId,
        string $idempotencyKey,
        public string $phaseId,
        public string $goodQuantity,
        public string $scrapQuantity = '0',
        public ?DefectReason $defectReason = null,
        public ?string $note = null,
        ?int $expectedVersion = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($tenantId, $actorUserId, $idempotencyKey, $expectedVersion, $occurredAt);
    }

    /**
     * Name of the command in the receipts.
     */
    public function name(): string
    {
        return 'declare_production';
    }
}
