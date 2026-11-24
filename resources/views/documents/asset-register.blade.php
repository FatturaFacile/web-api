<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm 10mm 16mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111827; margin: 0; }
        .header { border-bottom: 2px solid #0f172a; padding-bottom: 6px; margin-bottom: 10px; }
        .header-top { width: 100%; border-collapse: collapse; }
        .header-top td { border: 0; padding: 0; vertical-align: top; }
        .title { font-size: 14px; font-weight: bold; color: #0f172a; margin: 0 0 2px 0; }
        .subtitle { font-size: 9px; color: #475569; margin: 0; }
        .meta { text-align: right; font-size: 8.5px; color: #334155; }
        table.register { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.register th, table.register td { border: 1px solid #cbd5e1; padding: 3px 4px; }
        table.register th { background: #f1f5f9; font-weight: bold; text-align: left; font-size: 7.5px; text-transform: uppercase; }
        table.register th.num, table.register td.num { text-align: right; font-variant-numeric: tabular-nums; }
        tr.cat-header td { background: #e2e8f0; font-weight: bold; font-size: 8.5px; }
        tr.cat-subtotal td { background: #f8fafc; font-weight: bold; border-top: 1.5px solid #64748b; }
        tr.grand-total td { background: #0f172a; color: #ffffff; font-weight: bold; font-size: 8.5px; }
        .badge { display: inline-block; padding: 1px 4px; background: #e0f2fe; color: #0369a1; border-radius: 2px; font-size: 7px; }
        .footer { margin-top: 10px; font-size: 7.5px; color: #64748b; text-align: right; }
    </style>
</head>
<body>
@php
    $lang = explode('-', (string) $locale)[0];
    $t = static fn (string $key): string => (string) __('eam.register.'.$key, [], $lang);
    $grouped = collect($rows)->groupBy(static fn (array $r): string => (string) ($r['category_code'] ?: 'UNCATEGORIZED'));
    $subtotalsByCode = collect($categorySubtotals)->keyBy('category_code');
@endphp

<div class="header">
    <table class="header-top">
        <tr>
            <td>
                <div class="title">{{ $t('title') }} — {{ $header['year'] }}</div>
                <p class="subtitle">{{ $header['legal_reference'] }}</p>
                <p class="subtitle">
                    <strong>{{ $header['legal_name'] }}</strong>
                    @if(!empty($header['tax_id'])) &bull; {{ $header['jurisdiction'] === 'CH' ? 'UID/MWST' : 'P.IVA' }}: {{ $header['tax_id'] }} @endif
                    @if(!empty($header['fiscal_code'])) &bull; CF: {{ $header['fiscal_code'] }} @endif
                </p>
            </td>
            <td class="meta">
                <div><strong>{{ $t('ledger') }}:</strong> {{ strtoupper((string) $header['ledger_type']) }}</div>
                <div><strong>{{ $t('currency') }}:</strong> {{ $header['currency'] }}</div>
                <div><strong>{{ $t('generated_at') }}:</strong> {{ substr((string) $header['generated_at'], 0, 10) }}</div>
            </td>
        </tr>
    </table>
</div>

<table class="register">
    <thead>
    <tr>
        <th>{{ $t('code') }}</th>
        <th>{{ $t('asset_name') }}</th>
        <th>{{ $t('acq_year') }}</th>
        <th>{{ $t('invoice_ref') }}</th>
        <th class="num">{{ $t('rate') }}</th>
        <th class="num">{{ $t('cost_start') }}</th>
        <th class="num">{{ $t('additions') }}</th>
        <th class="num">{{ $t('improvements') }}</th>
        <th class="num">{{ $t('disposals_cost') }}</th>
        <th class="num">{{ $t('cost_end') }}</th>
        <th class="num">{{ $t('accum_start') }}</th>
        <th class="num">{{ $t('depr_year') }}</th>
        <th class="num">{{ $t('disposals_accum') }}</th>
        <th class="num">{{ $t('value_adj') }}</th>
        <th class="num">{{ $t('accum_end') }}</th>
        <th class="num">{{ $t('nbv_end') }}</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($grouped as $catCode => $catRows)
        @php($sub = $subtotalsByCode->get($catCode))
        <tr class="cat-header">
            <td colspan="16">{{ $catCode }} — {{ $catRows->first()['category_name'] ?? $catCode }}</td>
        </tr>
        @foreach ($catRows as $row)
            <tr>
                <td>{{ $row['asset_number'] }}</td>
                <td>
                    {{ $row['name'] }}
                    @if(!empty($row['first_year_half_applied'])) <span class="badge">50% Y1</span> @endif
                    @if(!empty($row['incentive_regime']) && $row['incentive_regime'] !== 'none') <span class="badge">{{ strtoupper((string) $row['incentive_regime']) }}</span> @endif
                </td>
                <td>{{ $row['acquisition_year'] ?? '—' }}</td>
                <td>{{ $row['purchase_invoice_number'] ?: '—' }}</td>
                <td class="num">{{ number_format((float) $row['rate_percent'], 2, '.', '') }}%</td>
                <td class="num">{{ number_format((float) $row['cost_start'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $row['additions'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $row['improvements_year'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $row['disposals_cost'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $row['cost_end'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $row['accumulated_start'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $row['depreciation_year'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $row['disposals_accumulated'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $row['value_adjustments'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $row['accumulated_end'], 2, '.', "'") }}</td>
                <td class="num"><strong>{{ number_format((float) $row['net_book_value_end'], 2, '.', "'") }}</strong></td>
            </tr>
        @endforeach
        @if($sub)
            <tr class="cat-subtotal">
                <td colspan="5">{{ $t('subtotal') }} {{ $catCode }} ({{ $sub['assets_count'] }})</td>
                <td class="num">{{ number_format((float) $sub['cost_start'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['additions'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['improvements_year'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['disposals_cost'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['cost_end'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['accumulated_start'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['depreciation_year'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['disposals_accumulated'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['value_adjustments'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['accumulated_end'], 2, '.', "'") }}</td>
                <td class="num">{{ number_format((float) $sub['net_book_value_end'], 2, '.', "'") }}</td>
            </tr>
        @endif
    @empty
        <tr>
            <td colspan="16" style="text-align: center; padding: 14px;">{{ $t('empty') }}</td>
        </tr>
    @endforelse
    </tbody>
    <tfoot>
    <tr class="grand-total">
        <td colspan="5">{{ $t('grand_total') }}</td>
        <td class="num">{{ number_format((float) ($totals['cost_start'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['additions'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['improvements_year'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['disposals_cost'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['cost_end'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['accumulated_start'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['depreciation_year'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['disposals_accumulated'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['value_adjustments'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['accumulated_end'] ?? 0), 2, '.', "'") }}</td>
        <td class="num">{{ number_format((float) ($totals['net_book_value_end'] ?? 0), 2, '.', "'") }}</td>
    </tr>
    </tfoot>
</table>

<div class="footer">
    FatturaFacile ERP &bull; {{ $header['legal_reference'] }} &bull; {{ $header['year'] }}
</div>
</body>
</html>
