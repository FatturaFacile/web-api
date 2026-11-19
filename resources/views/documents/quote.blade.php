@extends('documents.layout')

@section('meta')
    @if ($valid_until) {{ __('pdf.valid_until') }}: {{ $valid_until }}<br> @endif
@endsection

@section('content')
    @if ($custom_text)
        <h2>{{ __('pdf.quote.terms') }}</h2>
        <div>{{ $custom_text }}</div>
    @endif

    @if (($cpq ?? null) !== null)
        @include('documents.partials.quote-cpq-lines')
    @else
        @include('documents.partials.lines', ['showDiscount' => false])
    @endif
    @include('documents.partials.totals')

    @if (! empty($cpq['vat']))
        <h2>{{ __('pdf.quote.cpq_vat_breakdown') }}</h2>
        <table role="presentation">
            <thead>
                <tr>
                    <th class="right">{{ __('pdf.quote.cpq_vat_rate') }}</th>
                    <th class="right">{{ __('pdf.quote.cpq_vat_taxable') }}</th>
                    <th class="right">{{ __('pdf.quote.cpq_vat_amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cpq['vat'] as $vatRow)
                    <tr>
                        <td class="right">{{ number_format($vatRow['rate'], 2) }}%</td>
                        <td class="right">{{ number_format($vatRow['taxable'], 2) }} {{ $currency }}</td>
                        <td class="right">{{ number_format($vatRow['tax'], 2) }} {{ $currency }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (($cpq ?? null) !== null)
        <h2>{{ __('pdf.quote.cpq_commercial_terms') }}</h2>
        <div>{{ __('pdf.quote.cpq_terms_validity') }}</div>
        <div>{{ __('pdf.quote.cpq_terms_prices') }}</div>
        <div class="muted">{{ __('pdf.quote.cpq_disclaimer') }}</div>
    @endif

    @if ($notes)
        <h2>{{ __('pdf.notes') }}</h2>
        <div>{{ $notes }}</div>
    @endif

    @if ($signature !== null)
        @include('documents.partials.signature', ['title' => __('pdf.quote.acceptance')])
    @endif

    @if (! empty($cpq['audit']))
        <h2>{{ __('pdf.quote.cpq_audit_title') }}</h2>
        <table role="presentation">
            <tr><td>{{ __('pdf.quote.cpq_audit_signer') }}</td><td>{{ $cpq['audit']['signer_name'] }}@if ($cpq['audit']['signer_role']), {{ $cpq['audit']['signer_role'] }}@endif</td></tr>
            @if ($cpq['audit']['purchase_order_number'])
                <tr><td>{{ __('pdf.quote.cpq_audit_po') }}</td><td>{{ $cpq['audit']['purchase_order_number'] }}</td></tr>
            @endif
            <tr><td>{{ __('pdf.quote.cpq_audit_signed_at') }}</td><td>{{ $cpq['audit']['signed_at'] }}</td></tr>
            <tr><td>{{ __('pdf.quote.cpq_audit_ip') }}</td><td>{{ $cpq['audit']['ip_address'] }}</td></tr>
            <tr><td>{{ __('pdf.quote.cpq_audit_hash') }}</td><td style="font-family: monospace; font-size: 8px">{{ $cpq['audit']['payload_hash'] }}</td></tr>
        </table>
    @endif
@endsection
