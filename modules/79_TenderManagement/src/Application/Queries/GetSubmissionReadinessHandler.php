<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Services\EnvelopeService;

final class GetSubmissionReadinessHandler
{
    public function __construct(private readonly EnvelopeService $envelopes) {}

    /**
     * @return array{ready: bool, envelopes: array<string, array{complete: bool, items: list<array<string, mixed>>}>, issues: list<array<string, mixed>>}
     */
    public function handle(GetSubmissionReadinessQuery $query): array
    {
        return $this->envelopes->readiness(TenderParticipation::query()->with('notice')->findOrFail($query->participationId), $query->asOf ?? CarbonImmutable::now());
    }
}
