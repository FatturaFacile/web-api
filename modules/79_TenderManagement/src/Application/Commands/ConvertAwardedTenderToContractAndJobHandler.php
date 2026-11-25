<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\Projects\Models\Project;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Services\AwardConversionService;

final class ConvertAwardedTenderToContractAndJobHandler
{
    public function __construct(private readonly AwardConversionService $conversion) {}

    /**
     * @return array{participation: TenderParticipation, contract: ServiceContract, project: Project, references: array<string, string|null>}
     */
    public function handle(ConvertAwardedTenderToContractAndJobCommand $command): array
    {
        $participation = TenderParticipation::query()->with('notice')->lockForUpdate()->findOrFail($command->participationId);

        return $this->conversion->convert($participation, [
            'contact_id' => $command->contactId,
            'project_manager_user_id' => $command->projectManagerUserId,
            'planned_start_date' => $command->plannedStartDate,
            'planned_end_date' => $command->plannedEndDate,
            'contract_title' => $command->contractTitle,
        ], $command->userId, $command->asOf ?? CarbonImmutable::today());
    }
}
