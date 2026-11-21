<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\AnalyticsDss\Application\Commands\ExportBoardPackCommand;
use Modules\AnalyticsDss\Application\Queries\GetExecutiveDssOverviewQuery;
use Modules\AnalyticsDss\Application\Queries\GetProfitabilityMatrixQuery;
use Modules\AnalyticsDss\Application\Queries\GetVarianceBridgeQuery;
use Modules\AnalyticsDss\Infrastructure\Export\BoardPackRenderer;

/**
 * Builds the Board Pack file from the same read models the cockpit shows, so the document always matches the screen.
 */
final class ExportBoardPackHandler
{
    public function __construct(
        private readonly GetExecutiveDssOverviewHandler $overview,
        private readonly GetVarianceBridgeHandler $bridge,
        private readonly GetProfitabilityMatrixHandler $matrix,
        private readonly BoardPackRenderer $renderer,
    ) {}

    /**
     * Returns the file as a temporary path to stream and delete.
     *
     * @return array{path: string, filename: string, content_type: string}
     */
    public function handle(ExportBoardPackCommand $command): array
    {
        $filter = $command->filter;
        $overview = $this->overview->handle(new GetExecutiveDssOverviewQuery($filter));
        $bridge = $this->bridge->handle(new GetVarianceBridgeQuery($filter));
        $matrix = $this->matrix->handle(new GetProfitabilityMatrixQuery($filter, 'customer', 50));
        $name = 'board-pack-'.$filter->to.'-'.strtolower($filter->currency);
        $path = tempnam(sys_get_temp_dir(), 'dss');

        if ($command->format === 'xlsx') {
            $this->renderer->xlsx($path, $overview, $bridge, $matrix, $command->locale);

            return ['path' => $path, 'filename' => $name.'.xlsx', 'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        $tenantName = (string) DB::table('tenants')->where('id', $filter->tenantId)->value('name');
        file_put_contents($path, $this->renderer->pdf($overview, $bridge, $matrix, $command->locale, $tenantName));

        return ['path' => $path, 'filename' => $name.'.pdf', 'content_type' => 'application/pdf'];
    }
}
