<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('associations.pdf.receipt_title') }} {{ $receipt->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2933; }
        h1 { font-size: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8mm; }
        td { padding: 2mm; border-bottom: 1px solid #cbd2d9; }
        td.label { width: 40%; color: #616e7c; }
    </style>
</head>
<body>
    <strong>{{ $tenant?->legal_name ?: $tenant?->name }}</strong>
    @if ($tenant?->fiscal_code)
        <br>{{ $tenant->fiscal_code }}
    @endif
    <h1>{{ __('associations.pdf.receipt_title') }} {{ $receipt->number }}</h1>
    <table>
        <tr><td class="label">{{ __('associations.pdf.date') }}</td><td>{{ $receipt->issued_on->format('d/m/Y') }}</td></tr>
        <tr><td class="label">{{ __('associations.pdf.received_from') }}</td><td>{{ $holder['payer'] ?? $holder['name'] }}</td></tr>
        @if (! empty($holder['payer']))
            <tr><td class="label">{{ __('associations.pdf.on_behalf_of') }}</td><td>{{ $holder['name'] }}</td></tr>
        @endif
        <tr><td class="label">{{ __('associations.pdf.for') }}</td><td>{{ $receipt->description }}</td></tr>
        <tr><td class="label">{{ __('associations.pdf.amount') }}</td><td>{{ number_format((float) $receipt->amount, 2, ',', '.') }} {{ $tenant?->default_currency ?: 'EUR' }}</td></tr>
        @if ($receipt->kind === \Modules\Associations\Enums\ReceiptKind::Full)
            <tr><td class="label">{{ __('associations.pdf.tax_code') }}</td><td>{{ $holder['tax_code'] ?? '' }}</td></tr>
            <tr><td class="label">{{ __('associations.pdf.birth_date') }}</td><td>{{ $holder['birth_date'] ?? '' }}</td></tr>
            <tr><td class="label">{{ __('associations.pdf.address') }}</td><td>{{ $holder['address'] ?? '' }}</td></tr>
            <tr><td class="label">{{ __('associations.pdf.email') }}</td><td>{{ $holder['email'] ?? '' }}</td></tr>
            @if (! empty($holder['discipline']))
                <tr><td class="label">{{ __('associations.pdf.discipline') }}</td><td>{{ $holder['discipline'] }}</td></tr>
            @endif
        @endif
    </table>
</body>
</html>
