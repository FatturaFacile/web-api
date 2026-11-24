<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('association_finance.receipt_title') }} {{ $receipt->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2933; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6mm; }
        td { padding: 2mm; border-bottom: 1px solid #cbd2d9; }
        td.label { width: 40%; color: #616e7c; }
        .note { margin-top: 8mm; font-size: 9px; color: #616e7c; }
        .cancelled { color: #d64545; font-weight: bold; }
    </style>
</head>
<body>
    <strong>{{ $tenant?->legal_name ?: $tenant?->name }}</strong>
    @if ($tenant?->fiscal_code)
        <br>{{ $tenant->fiscal_code }}
    @endif
    <h1>{{ __('association_finance.receipt_title') }} {{ $receipt->number }}</h1>
    @if ($receipt->cancelled_at)
        <p class="cancelled">{{ __('association_finance.receipt_is_cancelled') }}</p>
    @endif
    <p>{{ __('association_finance.receipt_statement') }}</p>
    <table>
        <tr><td class="label">{{ __('association_finance.receipt_date') }}</td><td>{{ $receipt->issued_on->format('d/m/Y') }}</td></tr>
        <tr><td class="label">{{ __('association_finance.receipt_donor') }}</td><td>{{ $receipt->holder['name'] ?? '' }}</td></tr>
        <tr><td class="label">{{ __('association_finance.receipt_tax_code') }}</td><td>{{ $receipt->holder['tax_code'] ?? '' }}</td></tr>
        <tr><td class="label">{{ __('association_finance.receipt_address') }}</td><td>{{ $receipt->holder['address'] ?? '' }}</td></tr>
        <tr><td class="label">{{ __('association_finance.receipt_received_on') }}</td><td>{{ $receipt->holder['received_on'] ?? '' }}</td></tr>
        @if (($receipt->holder['kind'] ?? 'money') === 'in_kind')
            <tr><td class="label">{{ __('association_finance.receipt_in_kind') }}</td><td>{{ $receipt->holder['in_kind_description'] ?? '' }}</td></tr>
            <tr><td class="label">{{ __('association_finance.receipt_declared_value') }}</td><td>{{ number_format((float) $receipt->amount, 2, ',', '.') }} {{ $tenant?->default_currency ?: 'EUR' }}</td></tr>
        @else
            <tr><td class="label">{{ __('association_finance.receipt_amount') }}</td><td>{{ number_format((float) $receipt->amount, 2, ',', '.') }} {{ $tenant?->default_currency ?: 'EUR' }}</td></tr>
            <tr><td class="label">{{ __('association_finance.receipt_method') }}</td><td>{{ $receipt->holder['payment_method'] ?? '' }}</td></tr>
        @endif
        @if (! empty($receipt->holder['campaign']))
            <tr><td class="label">{{ __('association_finance.receipt_campaign') }}</td><td>{{ $receipt->holder['campaign'] }}</td></tr>
        @endif
    </table>
    <p class="note">{{ __('association_finance.receipt_tax_note') }}</p>
</body>
</html>
