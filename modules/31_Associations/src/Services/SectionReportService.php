<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Modules\Associations\Models\Section;
use Modules\Associations\Support\Money;

/**
 * Separate accounts of the sections and the consolidated view. A section is a cost centre: its figures are the
 * postings on that cost centre, and what no section holds is shown apart so the parts always add up to the whole.
 */
final class SectionReportService
{
    public function __construct(private readonly ThirdSectorStatementService $statements) {}

    /**
     * @return array{year: int, sections: list<array<string, mixed>>, not_assigned: array<string, string>, consolidated: array<string, string>}
     */
    public function report(int $year): array
    {
        $consolidated = $this->statements->managementReport($year)['totals'];
        $sections = [];
        $assigned = ['revenues' => '0.00', 'costs' => '0.00'];

        foreach (Section::query()->where('is_active', true)->orderBy('code')->get() as $section) {
            $totals = $this->statements->managementReport($year, [$section->cost_center_id])['totals'];
            $sections[] = [
                'section_id' => $section->id,
                'code' => $section->code,
                'name' => $section->name,
                'cost_center_id' => $section->cost_center_id,
                'revenues' => $totals['revenues'],
                'costs' => $totals['costs'],
                'result' => Money::sub($totals['revenues'], $totals['costs']),
            ];
            $assigned['revenues'] = Money::add($assigned['revenues'], $totals['revenues']);
            $assigned['costs'] = Money::add($assigned['costs'], $totals['costs']);
        }

        $notAssignedRevenues = Money::sub($consolidated['revenues'], $assigned['revenues']);
        $notAssignedCosts = Money::sub($consolidated['costs'], $assigned['costs']);

        return [
            'year' => $year,
            'sections' => $sections,
            'not_assigned' => ['revenues' => $notAssignedRevenues, 'costs' => $notAssignedCosts, 'result' => Money::sub($notAssignedRevenues, $notAssignedCosts)],
            'consolidated' => ['revenues' => $consolidated['revenues'], 'costs' => $consolidated['costs'], 'result' => Money::sub($consolidated['revenues'], $consolidated['costs'])],
        ];
    }
}
