<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Modules\GreenCare\Domain\Compliance\ComplianceStrategyResolver;
use Modules\GreenCare\Models\GreenSpaceSite;
use Modules\GreenCare\Models\PhytosanitaryTreatmentLog;

/**
 * PDF of the register of the phytosanitary treatments (Registro dei trattamenti fitosanitari / Quaderno di Campagna) for an inspection,
 * filtered by period, site or operator. The columns follow the strategy of the jurisdiction; the language follows the user.
 */
final class TreatmentRegisterExporter
{
    public function __construct(
        private readonly ComplianceStrategyResolver $strategies,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array{from?: string|null, to?: string|null, site_id?: string|null, operator_id?: string|null}  $filters
     * @return Collection<int, PhytosanitaryTreatmentLog>
     */
    public function logs(array $filters): Collection
    {
        return PhytosanitaryTreatmentLog::query()
            ->with(['site', 'operator', 'license'])
            ->when(! empty($filters['from']), static fn ($query) => $query->whereDate('treatment_date', '>=', $filters['from']))
            ->when(! empty($filters['to']), static fn ($query) => $query->whereDate('treatment_date', '<=', $filters['to']))
            ->when(! empty($filters['site_id']), static fn ($query) => $query->where('site_id', $filters['site_id']))
            ->when(! empty($filters['operator_id']), static fn ($query) => $query->where('operator_id', $filters['operator_id']))
            ->orderBy('treatment_date')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  array{from?: string|null, to?: string|null, site_id?: string|null, operator_id?: string|null}  $filters
     */
    public function pdf(array $filters): string
    {
        $tenant = Tenant::query()->find((string) $this->tenants->getTenantId());
        $logs = $this->logs($filters);
        $jurisdiction = $this->jurisdictionOf($logs, $filters, (string) ($tenant->country ?? 'IT'));
        $register = $this->strategies->for($jurisdiction)->registerExport($logs);

        $head = '<tr>'.implode('', array_map(static fn (string $column): string => '<th>'.e($column).'</th>', $register['columns'])).'</tr>';
        $body = implode('', array_map(
            static fn (array $row): string => '<tr>'.implode('', array_map(static fn (string $cell): string => '<td>'.e($cell).'</td>', $row)).'</tr>',
            $register['rows'],
        ));

        $meta = [
            __('green_care.register.company').': '.($tenant->legal_name ?: $tenant->name ?? ''),
            __('green_care.register.period').': '.($filters['from'] ?? '…').' → '.($filters['to'] ?? '…'),
            __('green_care.register.generated').': '.now()->format('d/m/Y H:i'),
        ];

        $style = 'body{font-family:DejaVu Sans,sans-serif;font-size:8px}h1{font-size:14px}p{margin:1px 0}'
            .'table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #999;padding:2px 3px;text-align:left}th{background:#eee}';

        $html = '<html><head><meta charset="utf-8"><style>'.$style.'</style></head><body><h1>'.e($register['title']).'</h1>'
            .implode('', array_map(static fn (string $line): string => '<p>'.e($line).'</p>', $meta))
            .'<table><thead>'.$head.'</thead><tbody>'.$body.'</tbody></table>'
            .'<p style="margin-top:8px">'.e((string) __('green_care.register.entries', ['count' => count($register['rows'])])).'</p></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }

    /**
     * The jurisdiction of the register: the one of the filtered site, else the one of the entries, else the country of the company.
     *
     * @param  Collection<int, PhytosanitaryTreatmentLog>  $logs
     * @param  array{site_id?: string|null}  $filters
     */
    private function jurisdictionOf(Collection $logs, array $filters, string $country): string
    {
        if (! empty($filters['site_id'])) {
            $site = GreenSpaceSite::query()->find($filters['site_id']);

            if ($site !== null) {
                return $site->jurisdiction;
            }
        }

        $first = $logs->first();

        return $first !== null ? $first->jurisdiction : $this->strategies->normalize($country);
    }
}
