<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Application\Commands;

use Modules\MrpPlanning\Domain\Enums\ReleaseAction;

/**
 * Firms, releases or dismisses selected proposals; releasing creates purchase requisitions (Mod 044/046) and production orders (Mod 064) and records the traceability links.
 */
final readonly class FirmAndReleaseMrpProposalsCommand
{
    /**
     * @param  list<string>  $proposalIds
     */
    public function __construct(
        public string $tenantId,
        public array $proposalIds,
        public ReleaseAction $action,
        public ?string $userId = null,
    ) {}
}
