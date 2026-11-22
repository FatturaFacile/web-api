<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Modules\Projects\Models\Project;

/**
 * The FatturaPA reference block (DatiContratto, or DatiConvenzione when the project is billed under a framework
 * agreement) that carries the CUP and CIG of a project on its invoices. Cross-border: the codes are emitted for any
 * project that has them, since the Swiss customers of an Italian public body still need them on the e-invoice.
 */
final class ProjectInvoiceReferenceBlock
{
    public const array Blocks = ['contract' => 'DatiContratto', 'convention' => 'DatiConvenzione'];

    /**
     * @return array{element: string, data: array<string, string>}|null null when the project has neither code
     */
    public function forProject(?string $projectId): ?array
    {
        if ($projectId === null) {
            return null;
        }

        $project = Project::query()->whereKey($projectId)->first();

        if ($project === null || ($this->blank($project->cup_code) && $this->blank($project->cig_code))) {
            return null;
        }

        $data = ['IdDocumento' => mb_substr((string) $project->project_number, 0, 20)];

        if (! $this->blank($project->cup_code)) {
            $data['CodiceCUP'] = (string) $project->cup_code;
        }

        if (! $this->blank($project->cig_code)) {
            $data['CodiceCIG'] = (string) $project->cig_code;
        }

        return [
            'element' => self::Blocks[$project->sdi_reference_block] ?? self::Blocks['contract'],
            'data' => $data,
        ];
    }

    private function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
