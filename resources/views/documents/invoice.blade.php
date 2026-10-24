@extends('documents.layout')

@section('meta')
    @if ($due_date) {{ __('pdf.due_date') }}: {{ $due_date }}<br> @endif
@endsection

@section('content')
    @include('documents.partials.lines', ['showDiscount' => true])
    @include('documents.partials.totals')

    @if ($notes)
        <h2>{{ __('pdf.notes') }}</h2>
        <div>{{ $notes }}</div>
    @endif

    @if ($payment['method'] || $payment['terms'] || $payment['iban'])
        <h2>{{ __('pdf.payment.title') }}</h2>
        <table>
            @if ($payment['method']) <tr><td style="width: 30%">{{ __('pdf.payment.method') }}</td><td>{{ $payment['method'] }}</td></tr> @endif
            @if ($payment['terms']) <tr><td>{{ __('pdf.payment.terms') }}</td><td>{{ $payment['terms'] }}</td></tr> @endif
            @if ($payment['bank_name']) <tr><td>{{ __('pdf.payment.bank') }}</td><td>{{ $payment['bank_name'] }}</td></tr> @endif
            @if ($payment['iban']) <tr><td>{{ __('pdf.payment.iban') }}</td><td>{{ $payment['iban'] }}</td></tr> @endif
        </table>
    @endif


@endsection
