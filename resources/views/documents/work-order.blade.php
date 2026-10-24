@extends('documents.layout')

@section('content')
    <h2>{{ __('pdf.titles.work_order') }}</h2>
    <table>
        <tr><td style="width: 25%">{{ __('pdf.work_order.technician') }}</td><td style="width: 25%">{{ $technician }}</td><td style="width: 25%">{{ __('pdf.work_order.address') }}</td><td>{{ $intervention_address }}</td></tr>
        <tr><td>{{ __('pdf.work_order.started') }}</td><td>{{ $started_at }}</td><td>{{ __('pdf.work_order.ended') }}</td><td>{{ $ended_at }}</td></tr>
        <tr><td>{{ __('pdf.work_order.labor_hours') }}</td><td>{{ number_format($labor_hours, 2) }}</td><td></td><td></td></tr>
    </table>

    @include('documents.partials.lines', ['showDiscount' => false, 'showType' => true])
    @include('documents.partials.totals')

    @if ($notes)
        <h2>{{ __('pdf.notes') }}</h2>
        <div>{{ $notes }}</div>
    @endif

    @include('documents.partials.signature', ['title' => __('pdf.work_order.acceptance')])
@endsection