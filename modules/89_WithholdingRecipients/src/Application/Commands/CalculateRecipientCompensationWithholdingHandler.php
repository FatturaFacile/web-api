<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Application\Commands;

use Modules\WithholdingRecipients\Domain\Calculation\CompensationResult;
use Modules\WithholdingRecipients\Models\RecipientProfile;
use Modules\WithholdingRecipients\Services\CompensationEngine;

final class CalculateRecipientCompensationWithholdingHandler
{
    public function __construct(private readonly CompensationEngine $engine) {}

    public function handle(CalculateRecipientCompensationWithholdingCommand $command): CompensationResult
    {
        $profile = $command->recipientProfileId === null ? null : RecipientProfile::query()->findOrFail($command->recipientProfileId);

        return $this->engine->calculate($command->data, $profile);
    }
}
