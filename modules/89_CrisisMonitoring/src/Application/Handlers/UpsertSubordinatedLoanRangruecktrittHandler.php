<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Handlers;

use Modules\CrisisMonitoring\Application\Commands\UpsertSubordinatedLoanRangruecktrittCommand;
use Modules\CrisisMonitoring\Models\SubordinatedLoanRangruecktritt;

/**
 * Saves a loan of the Rangrücktritt registry. The attributes are already validated by the request.
 */
final class UpsertSubordinatedLoanRangruecktrittHandler
{
    public function handle(UpsertSubordinatedLoanRangruecktrittCommand $command): SubordinatedLoanRangruecktritt
    {
        if ($command->id === null) {
            return SubordinatedLoanRangruecktritt::query()->create($command->attributes);
        }

        $loan = SubordinatedLoanRangruecktritt::query()->findOrFail($command->id);
        $loan->update($command->attributes);

        return $loan->refresh();
    }
}
