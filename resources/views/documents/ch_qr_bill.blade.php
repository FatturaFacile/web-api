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

    @if ($qr_error)
        <div class="box" style="margin-top: 16px; border-color: #d64545;">{{ __('pdf.qr.unavailable') }}</div>
    @endif

    @if ($qr)
        <style>
            .qr-slip { margin: 8mm -18mm 0 -18mm; width: 210mm; height: 105mm; page-break-inside: avoid; border-top: 1px dashed #000; }
            .qr-slip table { width: 100%; border-collapse: collapse; }
            .qr-slip td { border: 0; padding: 0; vertical-align: top; }
            .qr-receipt { width: 52mm; height: 105mm; padding: 5mm; border-right: 1px dashed #000; }
            .qr-payment { width: 138mm; height: 105mm; padding: 5mm; }
            .qr-title { font-size: 11pt; font-weight: bold; margin-bottom: 5mm; }
            .qr-label { font-size: 6pt; font-weight: bold; margin-top: 3mm; }
            .qr-value { font-size: 8pt; line-height: 1.15; }
            .qr-payment .qr-label { font-size: 8pt; margin-top: 3mm; }
            .qr-payment .qr-value { font-size: 10pt; }
            .qr-image { width: 46mm; height: 46mm; }
            .qr-blank { width: 52mm; height: 20mm; border: 1px solid #000; }
            .qr-blank-wide { width: 65mm; height: 25mm; border: 1px solid #000; }
            .qr-right { text-align: right; font-size: 6pt; }
        </style>

        <div class="qr-slip">
            <table role="presentation">
                <tr>
                    {{-- Receipt --}}
                    <td class="qr-receipt">
                        <div class="qr-title">{{ __('pdf.qr.receipt') }}</div>

                        <div class="qr-label">{{ __('pdf.qr.account_payable_to') }}</div>
                        <div class="qr-value">
                            {{ $qr['iban'] }}<br>
                            @foreach ($qr['creditor_lines'] as $line) {{ $line }}<br> @endforeach
                        </div>

                        @if ($qr['reference'])
                            <div class="qr-label">{{ __('pdf.qr.reference') }}</div>
                            <div class="qr-value">{{ $qr['reference'] }}</div>
                        @endif

                        @if ($qr['debtor_lines'])
                            <div class="qr-label">{{ __('pdf.qr.payable_by') }}</div>
                            <div class="qr-value">@foreach ($qr['debtor_lines'] as $line) {{ $line }}<br> @endforeach</div>
                        @else
                            <div class="qr-label">{{ __('pdf.qr.payable_by_blank') }}</div>
                            <div class="qr-blank"></div>
                        @endif

                        <table role="presentation" style="margin-top: 8mm">
                            <tr>
                                <td style="width: 16mm"><div class="qr-label">{{ __('pdf.qr.currency') }}</div><div class="qr-value">{{ $qr['currency'] }}</div></td>
                                <td><div class="qr-label">{{ __('pdf.qr.amount') }}</div><div class="qr-value">{{ $qr['amount'] }}</div></td>
                            </tr>
                        </table>

                        <div class="qr-right" style="margin-top: 4mm">{{ __('pdf.qr.acceptance_point') }}</div>
                    </td>

                    {{-- Payment part --}}
                    <td class="qr-payment">
                        <table role="presentation">
                            <tr>
                                <td style="width: 56mm">
                                    <div class="qr-title">{{ __('pdf.qr.payment_part') }}</div>
                                    <img class="qr-image" src="{{ $qr['image'] }}" alt="">
                                    <table role="presentation" style="margin-top: 5mm">
                                        <tr>
                                            <td style="width: 18mm"><div class="qr-label">{{ __('pdf.qr.currency') }}</div><div class="qr-value">{{ $qr['currency'] }}</div></td>
                                            <td><div class="qr-label">{{ __('pdf.qr.amount') }}</div><div class="qr-value">{{ $qr['amount'] }}</div></td>
                                        </tr>
                                    </table>
                                </td>
                                <td style="padding-left: 4mm">
                                    <div class="qr-label" style="margin-top: 0">{{ __('pdf.qr.account_payable_to') }}</div>
                                    <div class="qr-value">
                                        {{ $qr['iban'] }}<br>
                                        @foreach ($qr['creditor_lines'] as $line) {{ $line }}<br> @endforeach
                                    </div>

                                    @if ($qr['reference'])
                                        <div class="qr-label">{{ __('pdf.qr.reference') }}</div>
                                        <div class="qr-value">{{ $qr['reference'] }}</div>
                                    @endif

                                    @if ($qr['message'])
                                        <div class="qr-label">{{ __('pdf.qr.additional_information') }}</div>
                                        <div class="qr-value">{{ $qr['message'] }}</div>
                                    @endif

                                    @if ($qr['debtor_lines'])
                                        <div class="qr-label">{{ __('pdf.qr.payable_by') }}</div>
                                        <div class="qr-value">@foreach ($qr['debtor_lines'] as $line) {{ $line }}<br> @endforeach</div>
                                    @else
                                        <div class="qr-label">{{ __('pdf.qr.payable_by_blank') }}</div>
                                        <div class="qr-blank-wide"></div>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </div>
    @endif
@endsection
