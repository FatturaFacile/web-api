<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Documents;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Collection;
use Modules\Beekeeping\Models\Apiary;
use Modules\Beekeeping\Models\CensusDeclaration;
use Modules\Beekeeping\Models\ExtractionBatch;
use Modules\Beekeeping\Models\Hive;
use Modules\Beekeeping\Models\JarringRun;
use Modules\Beekeeping\Models\NomadismMovement;
use Modules\Beekeeping\Models\SuperIntake;
use Modules\Beekeeping\Models\VarroaTreatment;

/**
 * PDF documents of the beekeeping module: hive QR label sheet, jar label, official veterinary treatment register, movement accompanying
 * document (Allegato C BDA / Begleitdokument) and census declaration. Texts come from lang/<locale>/beekeeping.php (`documents`).
 */
final class BeekeepingDocumentRenderer
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  Collection<int, Hive>  $hives
     */
    public function hiveQrSheet(Collection $hives, string $locale): string
    {
        $cells = '';

        foreach ($hives as $hive) {
            $cells .= '<div class="cell"><img src="'.$this->qr($this->hiveUrl($hive)).'" width="110" height="110"><div class="code">'.e($hive->code).'</div><div class="meta">'.e((string) $hive->apiary?->name).'</div></div>';
        }

        return $this->pdf($locale, $this->t($locale, 'qr_sheet_title'), '<div class="grid">'.$cells.'</div>', 'a4');
    }

    public function jarLabel(JarringRun $run, string $locale): string
    {
        $batch = $run->batch ?? ExtractionBatch::query()->findOrFail($run->extraction_batch_id);
        $tenant = $this->tenant();
        $country = $this->t($locale, $run->origin_country === 'CH' ? 'country_ch' : 'country_it');
        $origin = $this->t($locale, 'origin_'.$batch->botanical_origin);
        $weight = $run->jar_format_g >= 1000 ? number_format($run->jar_format_g / 1000, 2).' kg' : $run->jar_format_g.' g';

        $body = '<div class="label"><div class="brand">'.e((string) ($tenant->legal_name ?? $tenant->name ?? '')).'</div>'
            .'<h1>'.e($this->t($locale, 'honey')).' — '.e($origin).'</h1>'
            .'<table class="kv"><tr><td>'.e($this->t($locale, 'net_weight')).'</td><td>'.e($weight).'</td></tr>'
            .'<tr><td>'.e($this->t($locale, 'lot')).'</td><td>'.e($run->jar_lot_code).'</td></tr>'
            .'<tr><td>'.e($this->t($locale, 'best_before')).'</td><td>'.e($run->best_before->format('d/m/Y')).'</td></tr>'
            .'<tr><td>'.e($this->t($locale, 'origin_country')).'</td><td>'.e($country).'</td></tr></table>'
            .'<div class="meta">'.e((string) ($tenant->address ?? '')).' · '.e((string) ($tenant->vat_number ?? '')).'</div>'
            .'<img src="'.$this->qr($run->jar_lot_code).'" width="70" height="70"></div>';

        return $this->pdf($locale, $this->t($locale, 'jar_label_title'), $body, 'a5');
    }

    /**
     * @param  Collection<int, VarroaTreatment>  $treatments
     */
    public function varroaRegister(Collection $treatments, string $locale, ?int $year): string
    {
        $head = '<tr>'.implode('', array_map(fn (string $key): string => '<th>'.e($this->t($locale, $key)).'</th>', ['apiary', 'date', 'product', 'substance', 'drug_batch', 'prescription', 'veterinarian', 'dosage', 'withdrawal_days', 'withdrawal_end', 'scope'])).'</tr>';
        $rows = '';

        foreach ($treatments as $treatment) {
            $scope = $treatment->whole_apiary ? $this->t($locale, 'whole_apiary') : Hive::query()->whereIn('id', $treatment->hive_ids ?? [])->orderBy('code')->pluck('code')->implode(', ');
            $rows .= '<tr>'.implode('', array_map(static fn (?string $cell): string => '<td>'.e((string) $cell).'</td>', [
                $treatment->apiary?->code.' '.$treatment->apiary?->name,
                $treatment->treatment_date->format('d/m/Y'),
                $treatment->commercial_name ?: $treatment->product,
                $treatment->active_substance,
                $treatment->drug_batch,
                $treatment->prescription_ref,
                $treatment->veterinarian,
                $treatment->dosage,
                (string) $treatment->withdrawal_days,
                $treatment->withdrawal_end_date->format('d/m/Y'),
                $scope,
            ])).'</tr>';
        }

        $title = $this->t($locale, 'varroa_register_title').($year === null ? '' : ' '.$year);

        return $this->pdf($locale, $title, '<table class="grid-table"><thead>'.$head.'</thead><tbody>'.$rows.'</tbody></table>', 'a4', true);
    }

    public function movementDocument(NomadismMovement $movement, string $locale): string
    {
        $origin = $movement->origin ?? Apiary::query()->findOrFail($movement->origin_apiary_id);
        $destination = $movement->destination;
        $swiss = $origin->country === 'CH';
        $title = $this->t($locale, $swiss ? 'movement_title_ch' : 'movement_title_it');

        $rows = [
            ['document_number', $movement->document_number],
            ['movement_kind', $this->t($locale, 'kind_'.$movement->kind)],
            ['origin', $origin->code.' — '.$origin->name.' ('.($origin->registry_code ?? '-').')'],
            ['destination', $destination === null ? (string) $movement->destination_label : $destination->code.' — '.$destination->name.' ('.($destination->registry_code ?? '-').')'],
            ['counterpart', (string) $movement->counterpart],
            ['subject', $this->t($locale, 'subject_'.$movement->subject).': '.$movement->quantity],
            ['target_flora', $movement->target_flora === null ? '' : $this->t($locale, 'origin_'.$movement->target_flora)],
            ['vehicle_plate', (string) $movement->vehicle_plate],
            ['departure', $movement->departure_date->format('d/m/Y')],
            ['arrival', $movement->arrival_date?->format('d/m/Y') ?? ''],
        ];

        $body = '<table class="kv wide">';

        foreach ($rows as [$key, $value]) {
            $body .= '<tr><td>'.e($this->t($locale, $key)).'</td><td>'.e($value).'</td></tr>';
        }

        $body .= '</table><div class="sign">'.e($this->t($locale, 'signature')).'</div>';

        return $this->pdf($locale, $title, $body, 'a4');
    }

    public function censusDeclaration(CensusDeclaration $census, string $locale): string
    {
        $head = '<tr>'.implode('', array_map(fn (string $key): string => '<th>'.e($this->t($locale, $key)).'</th>', ['apiary', 'registry_code', 'municipality', 'hives', 'nuclei'])).'</tr>';
        $rows = '';

        foreach ($census->snapshot as $apiary) {
            $rows .= '<tr><td>'.e($apiary['code'].' '.$apiary['name']).'</td><td>'.e((string) $apiary['registry_code']).'</td><td>'.e((string) $apiary['municipality']).'</td><td>'.e((string) $apiary['hives']).'</td><td>'.e((string) $apiary['nuclei']).'</td></tr>';
        }

        $rows .= '<tr class="total"><td colspan="3">'.e($this->t($locale, 'total')).'</td><td>'.$census->total_hives.'</td><td>'.$census->total_nuclei.'</td></tr>';
        $title = $this->t($locale, $census->country === 'CH' ? 'census_title_ch' : 'census_title_it').' '.$census->census_year;

        return $this->pdf($locale, $title, '<table class="grid-table"><thead>'.$head.'</thead><tbody>'.$rows.'</tbody></table>', 'a4');
    }

    /**
     * @param  Collection<int, SuperIntake>  $intakes
     */
    public function haccpSheet(ExtractionBatch $batch, Collection $intakes, string $locale): string
    {
        $rows = '';

        foreach ($intakes as $intake) {
            $rows .= '<tr><td>'.e((string) $intake->apiary?->code).'</td><td>'.e($intake->harvested_on->format('d/m/Y')).'</td><td>'.e((string) $intake->net_weight_kg).'</td></tr>';
        }

        $body = '<table class="kv wide"><tr><td>'.e($this->t($locale, 'lot')).'</td><td>'.e($batch->lot_code).'</td></tr></table>'
            .'<table class="grid-table"><thead><tr><th>'.e($this->t($locale, 'apiary')).'</th><th>'.e($this->t($locale, 'date')).'</th><th>kg</th></tr></thead><tbody>'.$rows.'</tbody></table>';

        return $this->pdf($locale, $this->t($locale, 'haccp_title'), $body, 'a4');
    }

    private function hiveUrl(Hive $hive): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/beekeeping/hives/by-qr/'.$hive->qr_token;
    }

    private function qr(string $data): string
    {
        return 'data:image/png;base64,'.base64_encode((new PngWriter)->write(new QrCode(data: $data, size: 220, margin: 4))->getString());
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->findOrFail((string) $this->tenantContext->getTenantId());
    }

    private function t(string $locale, string $key): string
    {
        return (string) trans('beekeeping.documents.'.$key, [], $locale);
    }

    private function pdf(string $locale, string $title, string $body, string $paper, bool $landscape = false): string
    {
        $tenant = $this->tenant();
        $html = '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#1f2937}h1{font-size:16px;margin:4px 0}.brand{font-size:12px;font-weight:bold}.meta{color:#6b7280;font-size:8px}'
            .'.grid{width:100%}.cell{display:inline-block;width:23%;margin:6px 1%;padding:6px;border:1px dashed #9ca3af;text-align:center}.code{font-size:13px;font-weight:bold}'
            .'table{border-collapse:collapse;width:100%}.grid-table th{background:#e5e7eb;text-align:left;padding:4px;border:1px solid #d1d5db}.grid-table td{padding:3px 4px;border:1px solid #e5e7eb}'
            .'.kv td{padding:4px 6px;border-bottom:1px solid #e5e7eb}.kv td:first-child{color:#6b7280;width:38%}.label{border:2px solid #92400e;padding:14px;border-radius:6px}.total td{font-weight:bold;background:#f3f4f6}.sign{margin-top:48px;border-top:1px solid #111;width:220px;padding-top:4px}'
            .'</style></head><body><div class="brand">'.e((string) ($tenant->legal_name ?? $tenant->name ?? '')).'</div><h1>'.e($title).'</h1><div class="meta">'
            .e($this->t($locale, 'generated_at')).' '.e(CarbonImmutable::now()->format('d/m/Y H:i')).'</div>'.$body.'</body></html>';

        return Pdf::loadHTML($html)->setPaper($paper, $landscape ? 'landscape' : 'portrait')->output();
    }
}
