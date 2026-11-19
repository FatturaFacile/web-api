<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('field_service.pdf.title') }} {{ $report->report_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        h2 { font-size: 12px; margin: 14px 0 4px 0; border-bottom: 1px solid #9ca3af; padding-bottom: 2px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        .num { text-align: right; }
        .covered { color: #047857; }
        .meta td { padding: 1px 4px; }
        .sig { margin-top: 8px; }
        .hash { font-family: DejaVu Sans Mono, monospace; font-size: 8px; word-break: break-all; }
    </style>
</head>
<body>
<h1>{{ __('field_service.pdf.title') }}</h1>
<table class="meta">
    <tr><td>{{ __('field_service.pdf.report_number') }}</td><td><strong>{{ $report->report_number }}</strong></td>
        <td>{{ __('field_service.pdf.work_order') }}</td><td>{{ $workOrder->work_order_number }}</td></tr>
    <tr><td>{{ __('field_service.pdf.customer') }}</td><td>{{ $customerName }}</td>
        <td>{{ __('field_service.pdf.technician') }}</td><td>{{ $technicianName }}</td></tr>
    <tr><td>{{ __('field_service.pdf.site') }}</td><td>{{ $siteAddress }}</td>
        <td>{{ __('field_service.pdf.equipment') }}</td><td>{{ $equipmentLabel }}</td></tr>
    <tr><td>{{ __('field_service.pdf.signed_at') }}</td><td>{{ $report->signed_at?->format('d.m.Y H:i') }}</td><td></td><td></td></tr>
</table>

<h2>{{ __('field_service.pdf.work_performed') }}</h2>
<p>{{ $report->work_performed_summary }}</p>

@if (! empty($report->checklist_json))
    <h2>{{ __('field_service.pdf.checklist') }}</h2>
    <table>
        @foreach ($report->checklist_json as $entry)
            <tr>
                <td>{{ $entry['item'] ?? '' }}</td>
                <td>{{ __('field_service.pdf.result_'.($entry['result'] ?? 'na')) }}</td>
                <td>{{ $entry['notes'] ?? '' }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if ($report->meter_reading_hours !== null || $report->meter_reading_cycles !== null)
    <h2>{{ __('field_service.pdf.meter_readings') }}</h2>
    <p>
        @if ($report->meter_reading_hours !== null){{ __('field_service.pdf.operating_hours') }}: {{ $report->meter_reading_hours }}@endif
        @if ($report->meter_reading_cycles !== null) &nbsp; {{ __('field_service.pdf.cycles') }}: {{ $report->meter_reading_cycles }}@endif
    </p>
@endif

<h2>{{ __('field_service.pdf.charges') }}</h2>
<table>
    <tr>
        <th>{{ __('field_service.pdf.description') }}</th>
        <th class="num">{{ __('field_service.pdf.quantity') }}</th>
        <th class="num">{{ __('field_service.pdf.unit_price') }}</th>
        <th class="num">{{ __('field_service.pdf.amount') }} ({{ $billing['currency'] }})</th>
        <th>{{ __('field_service.pdf.status') }}</th>
    </tr>
    @foreach ($billing['lines'] as $line)
        <tr class="{{ $line['covered'] ? 'covered' : '' }}">
            <td>{{ __('field_service.pdf.kind_'.$line['kind']) }}@if ($line['kind'] === 'part'): {{ $line['description'] }}@endif</td>
            <td class="num">{{ $line['quantity'] }}</td>
            <td class="num">{{ $line['unit_price'] }}</td>
            <td class="num">{{ $line['charged_amount'] }}</td>
            <td>{{ $line['covered'] ? __('field_service.pdf.covered') : __('field_service.pdf.billable') }}</td>
        </tr>
    @endforeach
    <tr>
        <td colspan="5">{{ __('field_service.pdf.travel_km') }}: {{ $report->travel_km }}</td>
    </tr>
    <tr>
        <td colspan="3" class="num"><strong>{{ __('field_service.pdf.total_covered') }}</strong></td>
        <td class="num">{{ $billing['covered_total'] }}</td><td></td>
    </tr>
    <tr>
        <td colspan="3" class="num"><strong>{{ __('field_service.pdf.total_billable') }}</strong></td>
        <td class="num"><strong>{{ $billing['billable_total'] }}</strong></td><td></td>
    </tr>
</table>

<h2>{{ __('field_service.pdf.signature') }}</h2>
<div class="sig">
    @if ($report->signature_svg_data)
        <img src="data:image/svg+xml;base64,{{ base64_encode($report->signature_svg_data) }}" style="height: 70px;" alt="">
    @endif
    <div>{{ __('field_service.pdf.signed_by') }}: {{ $report->customer_signer_name }}@if ($report->customer_signer_role) ({{ $report->customer_signer_role }})@endif</div>
    <div>{{ __('field_service.pdf.integrity_hash') }}:</div>
    <div class="hash">{{ $report->signature_sha256_hash }}</div>
</div>
</body>
</html>
