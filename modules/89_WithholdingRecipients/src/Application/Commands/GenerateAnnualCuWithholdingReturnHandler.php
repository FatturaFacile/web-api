<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

use Illuminate\Database\Eloquent\Collection;
use Modules\WithholdingRecipients\Models\CuAutonomousRecord;
use Modules\WithholdingRecipients\Services\AnnualReturnService;

final class GenerateAnnualCuWithholdingReturnHandler
{
    public function __construct(private readonly AnnualReturnService $returns) {}

    /**
     * @return Collection<int, CuAutonomousRecord>
     */
    public function handle(GenerateAnnualCuWithholdingReturnCommand $command): Collection
    {
        return $this->returns->generate($command->taxYear, $command->recipientProfileId, $command->userId);
    }
}
