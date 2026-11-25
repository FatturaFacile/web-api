<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Services\EligibilityEvaluator;
use Modules\TenderManagement\Services\VaultService;

final class EvaluateTenderEligibilityAndGoNoGoHandler
{
    public function __construct(
        private readonly EligibilityEvaluator $evaluator,
        private readonly VaultService $vault,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(EvaluateTenderEligibilityAndGoNoGoQuery $query): array
    {
        $participation = TenderParticipation::query()->with('notice')->findOrFail($query->participationId);
        $today = $query->asOf ?? CarbonImmutable::now();

        $result = ['participation_id' => (string) $participation->id] + $this->evaluator->evaluate($participation->notice, $this->vault->activeDocuments(), $today);

        if ($query->persist) {
            $participation->forceFill(['go_no_go_score' => $result])->save();
        }

        return $result;
    }
}
