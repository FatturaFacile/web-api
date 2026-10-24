@extends('documents.layout')

@section('meta')
    @if ($valid_until) {{ __('pdf.valid_until') }}: {{ $valid_until }}<br> @endif
@endsection

@section('content')
    @if ($custom_text)
        <h2>{{ __('pdf.quote.terms') }}</h2>
        <div>{{ $custom_text }}</div>
    @endif

    @include('documents.partials.lines', ['showDiscount' => false])
    @include('documents.partials.totals')

    @if ($notes)
        <h2>{{ __('pdf.notes') }}</h2>
        <div>{{ $notes }}</div>
    @endif

    @if ($signature !== null)
        @include('documents.partials.signature', ['title' => __('pdf.quote.acceptance')])
    @endif
@endsection