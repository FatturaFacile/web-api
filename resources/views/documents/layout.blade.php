<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __("pdf.titles.{$type}") }} {{ $number }}</title>
    <style>
        @page { margin: 28mm 18mm 24mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2933; }
        h1 { font-size: 18px; margin: 0 0 4px 0; }
        h2 { font-size: 11px; margin: 14px 0 4px 0; text-transform: uppercase; color: #52606d; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #f0f4f8; border-bottom: 1px solid #9fb3c8; padding: 5px 6px; }
        td { padding: 5px 6px; border-bottom: 1px solid #e4e7eb; vertical-align: top; }
        .right { text-align: right; }
        .muted { color: #7b8794; }
        .header td { border: 0; padding: 0; vertical-align: top; }
        .box { border: 1px solid #cbd2d9; padding: 8px; }
        .totals { width: 45%; margin-left: 55%; margin-top: 8px; }
        .totals td { border: 0; padding: 3px 6px; }
        .totals .grand td { border-top: 1px solid #1f2933; font-weight: bold; font-size: 11px; }
        .signature-line { margin-top: 36px; border-top: 1px solid #52606d; width: 220px; padding-top: 3px; }
        footer { position: fixed; bottom: -14mm; left: 0; right: 0; text-align: center; font-size: 8px; color: #7b8794; }
    </style>
</head>
<body>
    <footer>
        {{ $tenant?->legal_name ?: $tenant?->name }}
        @if ($tenant?->vat_number) · {{ __('pdf.vat_number') }} {{ $tenant->vat_number }} @endif
        @if ($tenant?->iban) · {{ __('pdf.payment.iban') }} {{ $tenant->iban }} @endif
    </footer>

    <table class="header">
        <tr>
            <td style="width: 55%">
                <strong style="font-size: 13px">{{ $tenant?->legal_name ?: $tenant?->name }}</strong><br>
                @foreach ($tenant_address_lines as $line) {{ $line }}<br> @endforeach
                @if ($tenant?->vat_number) {{ __('pdf.vat_number') }}: {{ $tenant->vat_number }}<br> @endif
                @if ($tenant?->fiscal_code) {{ __('pdf.fiscal_code') }}: {{ $tenant->fiscal_code }}<br> @endif
                @if ($tenant?->uid_number) {{ __('pdf.uid_number') }}: {{ $tenant->uid_number }}<br> @endif
            </td>
            <td style="width: 45%" class="right">
                <h1>{{ __("pdf.titles.{$type}") }}</h1>
                {{ __('pdf.number') }} <strong>{{ $number }}</strong><br>
                @isset($date) {{ __('pdf.date') }}: {{ $date }}<br> @endisset
                @yield('meta')
            </td>
        </tr>
    </table>

    <h2>{{ __('pdf.customer') }}</h2>
    <div class="box">
        <strong>{{ $contact_name }}</strong><br>
        @foreach ($address_lines as $line) {{ $line }}<br> @endforeach
        @if ($contact?->vat_number) {{ __('pdf.vat_number') }}: {{ $contact->vat_number }}<br> @endif
        @if ($contact?->fiscal_code) {{ __('pdf.fiscal_code') }}: {{ $contact->fiscal_code }}<br> @endif
    </div>

    @yield('content')
</body>
</html>