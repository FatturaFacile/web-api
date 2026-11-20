<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Queries;

use Modules\Analytics\Semantic\ReportEngine;

/**
 * Validates the specification against the permissions of the caller and returns one page of the report.
 */
final readonly class RunReportHandler
{
    public function __construct(private ReportEngine $engine) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(RunReportQuery $query): array
    {
        $spec = $this->engine->parse($query->dataset, $query->spec, $query->user, $query->errorPrefix);

        return $this->engine->run($spec, $query->user, $query->page, $query->perPage);
    }
}
