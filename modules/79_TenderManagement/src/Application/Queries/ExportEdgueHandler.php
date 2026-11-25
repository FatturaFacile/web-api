<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Services\EdgueGenerator;

final class ExportEdgueHandler
{
    public function __construct(private readonly EdgueGenerator $generator) {}

    /**
     * The eDGUE document, or the raw file contents for the xml and pdf formats.
     *
     * @return array<string, mixed>|string
     */
    public function handle(ExportEdgueQuery $query): array|string
    {
        $participation = TenderParticipation::query()->with('notice')->findOrFail($query->participationId);
        $today = $query->asOf ?? CarbonImmutable::now();

        return match ($query->format) {
            'pdf' => $this->generator->pdf($participation, $today),
            'xml' => $this->generator->generate($participation, $today)['xml'],
            default => $this->generator->generate($participation, $today),
        };
    }
}
