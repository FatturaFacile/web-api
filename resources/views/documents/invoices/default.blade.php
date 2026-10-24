@extends('documents.layouts.document-pdf')

@section('content')
    @include('documents.partials.header', ['document' => $invoice])
    @include('documents.partials.recipient', ['document' => $invoice])
    @include('documents.partials.lines-table', ['items' => $invoice->lines])

    <div class="avoid-break" style="margin-top: 10mm;">
        @include('documents.partials.totals', ['document' => $invoice])
    </div>

    @if($invoice->is_swiss_qr)
        {{-- Garantisce che la QR-bill parta su una pagina pulita se non c'è spazio sufficiente --}}
        <div class="page-break"></div>
        @include('documents.partials.swiss-qr-slip', ['invoice' => $invoice, 'qrBillBase64' => $qrBillBase64])
    @else
        <div class="avoid-break" style="margin-top: 15mm;">
            @include('documents.partials.payment-details', ['invoice' => $invoice])
        </div>
    @endif
@endsection
