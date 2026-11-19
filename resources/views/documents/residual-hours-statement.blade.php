<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f1f1f1; }
        .num { text-align: right; }
        .summary td { border: 0; padding: 2px 6px 2px 0; }
    </style>
</head>
<body>
@php($t = fn (string $key, array $r = []) => __('esm_residual_report.'.$key, $r, $locale))
<h1>{{ $t('title') }}</h1>
<p>{{ $contract->contract_number }} - {{ $contract->title }}<br>{{ $customerName }}</p>
<table class="summary">
    <tr><td>{{ $t('total') }}</td><td><strong>{{ $contract->included_hours }}</strong></td></tr>
    <tr><td>{{ $t('consumed') }}</td><td><strong>{{ $contract->consumed_hours }}</strong></td></tr>
    <tr><td>{{ $t('remaining') }}</td><td><strong>{{ $remaining }}</strong></td></tr>
</table>
<table>
    <thead>
    <tr>
        <th>{{ $t('date') }}</th><th>{{ $t('type') }}</th><th>{{ $t('reference') }}</th>
        <th>{{ $t('technician') }}</th><th>{{ $t('serial') }}</th>
        <th class="num">{{ $t('hours') }}</th><th class="num">{{ $t('balance') }}</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($transactions as $tx)
        <tr>
            <td>{{ $tx->occurred_at?->format('Y-m-d') }}</td>
            <td>{{ $t('types.'.$tx->type) }}</td>
            <td>{{ $tx->reference }}</td>
            <td>{{ $tx->technician_name }}</td>
            <td>{{ $tx->asset?->serial_number }}</td>
            <td class="num">{{ $tx->hours }}</td>
            <td class="num">{{ $tx->balance_after }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
