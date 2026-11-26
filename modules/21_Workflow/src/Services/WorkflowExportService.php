<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use App\Models\User;
use App\Services\Security\CsvFormulaNeutralizer;
use Barryvdh\DomPDF\Facade\Pdf;
use Modules\Workflow\Models\WorkflowInstance;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Documents produced from the engine: the "Evidenza di approvazione" of one instance (PDF, to attach to the document and
 * to archive in the DMS) and the SLA report (XLSX and PDF). The evidence lists date, time, identity, channel and reason of
 * every step as internal-control evidence (CO 728a/728b, D.Lgs. 231/2001); it does not calculate or assert any tax.
 */
final readonly class WorkflowExportService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private WorkflowTimelineService $timeline,
        private WorkflowAuditLedgerService $auditLedger,
        private ApprovalGuardService $guard,
        private ApprovableRegistry $registry
    ) {}

    /**
     * The evidence of one approval as a PDF.
     */
    public function evidencePdf(WorkflowInstance $instance, string $locale): string
    {
        return Pdf::loadHTML($this->evidenceHtml($instance, $locale))->setPaper('a4')->output();
    }

    /**
     * The SLA report as a workbook.
     *
     * @param  array<string, mixed>  $report  output of {@see WorkflowSlaReportService::report()}
     * @return string the binary content of the workbook
     */
    public function slaXlsx(array $report, string $locale): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wfsla');

        if ($path === false) {
            throw new RuntimeException('A temporary file for the SLA report could not be created.');
        }

        try {
            $t = static fn (string $key): string => __('workflow_exports.sla.'.$key, [], $locale);
            $writer = new Writer;
            $writer->openToFile($path);

            $writer->getCurrentSheet()->setName(mb_substr($t('by_definition'), 0, 31));
            $writer->addRow(Row::fromValues([$t('title'), $report['period']['from'], $report['period']['to']]));
            $writer->addRow(Row::fromValues([$t('definition'), $t('steps'), $t('on_time'), $t('late'), $t('timed_out'), $t('overdue'), $t('escalated'), $t('avg_hours')]));

            foreach ($report['by_definition'] as $row) {
                $writer->addRow(Row::fromValues([CsvFormulaNeutralizer::neutralize((string) $row['definition_name']), $row['steps'], $row['decided_on_time'], $row['decided_late'], $row['timed_out'], $row['pending_overdue'], $row['escalated'], $row['avg_hours_to_decide'] === null ? '' : (float) $row['avg_hours_to_decide']]));
            }

            foreach (['by_node' => ['node_name', 'node'], 'by_approver' => ['approver_name', 'approver']] as $section => [$nameKey, $label]) {
                $writer->addNewSheetAndMakeItCurrent()->setName(mb_substr($t($section), 0, 31));
                $writer->addRow(Row::fromValues([$t($label), $t('steps'), $t('open'), $t('overdue'), $t('avg_hours')]));

                foreach ($report[$section] ?? [] as $row) {
                    $writer->addRow(Row::fromValues([CsvFormulaNeutralizer::neutralize((string) $row[$nameKey]), $row['steps'], $row['open'], $row['overdue'], $row['avg_hours_to_decide'] === null ? '' : (float) $row['avg_hours_to_decide']]));
                }
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The SLA report as a PDF.
     *
     * @param  array<string, mixed>  $report
     */
    public function slaPdf(array $report, string $locale): string
    {
        $t = static fn (string $key): string => e(__('workflow_exports.sla.'.$key, [], $locale));
        $rows = '';

        foreach ($report['by_definition'] as $row) {
            $rows .= '<tr><td>'.e((string) $row['definition_name']).'</td><td class="n">'.$row['steps'].'</td><td class="n">'.$row['decided_on_time'].'</td><td class="n">'.$row['decided_late'].'</td><td class="n">'.$row['pending_overdue'].'</td><td class="n">'.e((string) ($row['avg_hours_to_decide'] ?? '-')).'</td></tr>';
        }

        $nodes = '';

        foreach ($report['by_node'] ?? [] as $row) {
            $nodes .= '<tr><td>'.e((string) $row['node_name']).'</td><td class="n">'.$row['steps'].'</td><td class="n">'.$row['overdue'].'</td><td class="n">'.e((string) ($row['avg_hours_to_decide'] ?? '-')).'</td></tr>';
        }

        $html = '<html><head><meta charset="utf-8"><style>'.$this->css().'</style></head><body>'
            .'<h1>'.$t('title').'</h1><p>'.e((string) $report['period']['from']).' – '.e((string) $report['period']['to']).'</p>'
            .'<h2>'.$t('by_definition').'</h2><table><tr><th>'.$t('definition').'</th><th>'.$t('steps').'</th><th>'.$t('on_time').'</th><th>'.$t('late').'</th><th>'.$t('overdue').'</th><th>'.$t('avg_hours').'</th></tr>'.$rows.'</table>'
            .'<h2>'.$t('by_node').'</h2><table><tr><th>'.$t('node').'</th><th>'.$t('steps').'</th><th>'.$t('overdue').'</th><th>'.$t('avg_hours').'</th></tr>'.$nodes.'</table>'
            .'</body></html>';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }

    /**
     * A file name for the evidence: the document type and the id keep downloads apart.
     */
    public function evidenceFileName(WorkflowInstance $instance): string
    {
        return 'approval-evidence-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $instance->approvable_id).'.pdf';
    }

    private function evidenceHtml(WorkflowInstance $instance, string $locale): string
    {
        $t = static fn (string $key, array $replace = []): string => e(__('workflow_exports.evidence.'.$key, $replace, $locale));
        $approvable = $instance->approvable;
        $requesterId = $this->guard->requesterOf($instance);
        $requester = $requesterId === null ? null : User::query()->whereKey($requesterId)->value('name');
        $amount = $this->guard->amountOf($instance);
        $chain = $this->auditLedger->verify($instance);

        $rows = '';

        foreach ($this->timeline->timeline($instance) as $item) {
            $moment = (string) ($item['decided_at'] ?? $item['at']);
            $status = $item['type'] === 'step' ? __('workflow_exports.status.'.$item['status'], [], $locale) : __('workflow_exports.event.'.$item['status'], [], $locale);
            $rows .= '<tr><td>'.e(substr($moment, 0, 10)).'</td><td>'.e(substr($moment, 11, 8)).'</td><td>'.e((string) ($item['actor'] ?? '-')).'</td><td>'.e((string) ($item['node_name'] ?? '')).'</td><td>'.e($status).'</td><td>'.e((string) ($item['channel'] ?? '')).'</td><td>'.e((string) ($item['comment'] ?? '')).'</td></tr>';
        }

        return '<html><head><meta charset="utf-8"><style>'.$this->css().'</style></head><body>'
            .'<h1>'.$t('title').'</h1>'
            .'<table class="meta">'
            .'<tr><th>'.$t('document').'</th><td>'.e($this->registry->labelOf($approvable)).' ('.e((string) ($approvable?->getMorphClass() ?? $instance->approvable_type)).')</td></tr>'
            .'<tr><th>'.$t('requester').'</th><td>'.e((string) ($requester ?? '-')).'</td></tr>'
            .'<tr><th>'.$t('amount').'</th><td>'.($amount === null ? '-' : e(number_format($amount, 2, '.', "'"))).'</td></tr>'
            .'<tr><th>'.$t('workflow').'</th><td>'.e((string) data_get($instance->definition, 'name', '')).' v'.e((string) $instance->workflow_definition_version).'</td></tr>'
            .'<tr><th>'.$t('status').'</th><td>'.e(__('workflow_exports.instance.'.$instance->status, [], $locale)).'</td></tr>'
            .'<tr><th>'.$t('started').'</th><td>'.e((string) $instance->started_at?->toDateTimeString()).'</td></tr>'
            .'<tr><th>'.$t('completed').'</th><td>'.e((string) ($instance->completed_at?->toDateTimeString() ?? '-')).'</td></tr>'
            .'</table>'
            .'<h2>'.$t('steps').'</h2>'
            .'<table><tr><th>'.$t('date').'</th><th>'.$t('time').'</th><th>'.$t('identity').'</th><th>'.$t('node').'</th><th>'.$t('outcome').'</th><th>'.$t('channel').'</th><th>'.$t('reason').'</th></tr>'.$rows.'</table>'
            .'<p>'.$t($chain['is_intact'] ? 'chain_intact' : 'chain_broken', ['count' => $chain['entries']]).'</p>'
            .'<p class="note">'.$t('neutrality').'</p>'
            .'</body></html>';
    }

    private function css(): string
    {
        return 'body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#222}h1{font-size:16px}h2{font-size:12px;margin-top:14px}table{width:100%;border-collapse:collapse;margin-top:6px}th,td{border:1px solid #ccc;padding:3px 5px;text-align:left;vertical-align:top}th{background:#f2f2f2}.n{text-align:right}.meta th{width:25%}.note{color:#555;font-size:9px;margin-top:14px}';
    }
}
