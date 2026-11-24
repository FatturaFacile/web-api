<?php

declare(strict_types=1);

namespace Modules\SwissVat\Application\Commands;

use Modules\SwissVat\Models\SwissVatXmlExport;
use Modules\SwissVat\Services\SwissVatReturnWorkflowService;

final readonly class ExportSuisseTaxXmlHandler
{
    public function __construct(private SwissVatReturnWorkflowService $workflow) {}

    public function handle(ExportSuisseTaxXmlCommand $command): SwissVatXmlExport
    {
        return $this->workflow->exportSuisseTaxXml($command->tenantId, $command->returnId, $command->actorUserId);
    }
}
