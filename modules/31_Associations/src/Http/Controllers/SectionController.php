<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\Services\SectionReportService;
use Modules\Associations\Support\AssociationCapability;

/**
 * Separate accounts of the sections and the consolidated view of the body. Sections are maintained through the
 * catalogs; the detailed statement of one section is the management report filtered by its cost centre.
 */
final class SectionController extends AssociationController
{
    #[AssociationGet('/api/v1/associations/sections/{year}/report', 'showAssociationSectionReport', 'Revenues, costs and result of every section, what no section holds, and the consolidated total', response: 'AssociationSectionReport')]
    public function report(string $year, SectionReportService $sections): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $sections->report((int) $year)]);
    }
}
