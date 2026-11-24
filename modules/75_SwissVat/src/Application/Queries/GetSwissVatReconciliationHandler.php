<?php

declare(strict_types=1);

namespace Modules\SwissVat\Application\Queries;

use Modules\SwissVat\Services\SwissVatReconciliationService;
use Modules\SwissVat\Services\SwissVatReturnWorkflowService;

final readonly class GetSwissVatReconciliationHandler
{
    public function __construct(
        private SwissVatReturnWorkflowService $workflow,
        private SwissVatReconciliationService $reconciliation,
    ) {}

    /**
     * @return array{
     *     return_id: string,
     *     period_label: string,
     *     accounting_method: string,
     *     quadratura_passed: bool,
     *     quadratura_report: array<string, mixed>,
     *     box_schedule: list<array<string, mixed>>,
     *     reconciliation_lines: list<array<string, mixed>>,
     * }
     */
    public function handle(GetSwissVatReconciliationQuery $query): array
    {
        $vatReturn = $this->workflow->find($query->tenantId, $query->returnId);

        return [
            'return_id' => (string) $vatReturn->id,
            'period_label' => (string) $vatReturn->period_label,
            'accounting_method' => (string) $vatReturn->accounting_method,
            'quadratura_passed' => (bool) $vatReturn->quadratura_passed,
            'quadratura_report' => $vatReturn->quadratura_report ?? [],
            'box_schedule' => $this->reconciliation->buildScheduleForReturn($vatReturn),
            'reconciliation_lines' => $vatReturn->reconciliation_lines ?? [],
        ];
    }
}
