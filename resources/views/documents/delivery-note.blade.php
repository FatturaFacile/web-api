@extends('documents.layout')

@section('content')
    @if ($shipping_address)
        <h2>{{ __('pdf.delivery.ship_to') }}</h2>
        <div class="box">{{ $shipping_address }}</div>
    @endif

    <h2>{{ __('pdf.delivery.transport') }}</h2>
    <table role="presentation">
        <tr><td style="width: 25%">{{ __('pdf.delivery.reason') }}</td><td style="width: 25%">{{ $transport['reason'] }}</td><td style="width: 25%">{{ __('pdf.delivery.appearance') }}</td><td>{{ $transport['goods_appearance'] }}</td></tr>
        <tr><td>{{ __('pdf.delivery.packages') }}</td><td>{{ $transport['packages_count'] }}</td><td>{{ __('pdf.delivery.weight') }}</td><td>{{ $transport['gross_weight_kg'] !== null ? number_format($transport['gross_weight_kg'], 2) : '' }}</td></tr>
        <tr><td>{{ __('pdf.delivery.carrier') }}</td><td>{{ $transport['carrier'] }}</td><td>{{ __('pdf.delivery.courier') }}</td><td>{{ $transport['courier'] }}</td></tr>
        <tr><td>{{ __('pdf.delivery.license_plate') }}</td><td>{{ $transport['license_plate'] }}</td><td>{{ __('pdf.delivery.departure') }}</td><td>{{ $transport['departure_time'] }}</td></tr>
    </table>

    @include('documents.partials.lines', ['showDiscount' => false])
    @include('documents.partials.totals')

    @if ($notes)
        <h2>{{ __('pdf.notes') }}</h2>
        <div>{{ $notes }}</div>
    @endif

    <div class="signature-line muted">{{ __('pdf.delivery.receipt') }}</div>
@endsection