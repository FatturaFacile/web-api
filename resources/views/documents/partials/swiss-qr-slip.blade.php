<style>
    .qr-bill-container {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 105mm;
        width: 210mm;
        border-top: 1px dashed #666666;
        box-sizing: border-box;
        page-break-inside: avoid;
    }
    .qr-bill-receipt {
        float: left;
        width: 62mm;
        height: 105mm;
        padding: 5mm;
        border-right: 1px dashed #666666;
        box-sizing: border-box;
        font-size: 8pt;
    }
    .qr-bill-payment-part {
        float: left;
        width: 148mm;
        height: 105mm;
        padding: 5mm;
        box-sizing: border-box;
        font-size: 9pt;
    }
    .qr-code-img {
        width: 46mm;
        height: 46mm;
        margin: 5mm 0;
    }
</style>

<div class="qr-bill-container">
    {{-- Ricevuta (Receipt) --}}
    <div class="qr-bill-receipt">
        <h3>{{ __('Receipt') }}</h3>
        <p><strong>{{ __('Account / Payable to') }}</strong><br>
           {{ $invoice->tenant->iban }}<br>
           {{ $invoice->tenant->name }}<br>
           {{ $invoice->tenant->address }}, {{ $invoice->tenant->postal_code }} {{ $invoice->tenant->city }}
        </p>
        @if($invoice->reference_number)
            <p><strong>{{ __('Reference') }}</strong><br>{{ $invoice->reference_number }}</p>
        @endif
        <p><strong>{{ __('Payable by') }}</strong><br>
           {{ $invoice->contact->name }}<br>
           {{ $invoice->contact->address }}, {{ $invoice->contact->postal_code }} {{ $invoice->contact->city }}
        </p>
        <p><strong>{{ __('Currency') }}</strong> {{ $invoice->currency }} &nbsp;&nbsp; <strong>{{ __('Amount') }}</strong> {{ number_format($invoice->total_amount, 2, '.', ' ') }}</p>
    </div>

    {{-- Sezione di Pagamento (Payment Part) --}}
    <div class="qr-bill-payment-part">
        <h3>{{ __('Payment part') }}</h3>
        {{-- QR Code generato come immagine base64 --}}
        <img src="{{ $qrBillBase64 }}" class="qr-code-img" alt="Swiss QR Code">

        <div style="float: right; width: 85mm;">
            <p><strong>{{ __('Account / Payable to') }}</strong><br>
               {{ $invoice->tenant->iban }}<br>
               {{ $invoice->tenant->name }}<br>
               {{ $invoice->tenant->address }}, {{ $invoice->tenant->postal_code }} {{ $invoice->tenant->city }}
            </p>
            @if($invoice->reference_number)
                <p><strong>{{ __('Reference') }}</strong><br>{{ $invoice->reference_number }}</p>
            @endif
            <p><strong>{{ __('Payable by') }}</strong><br>
               {{ $invoice->contact->name }}<br>
               {{ $invoice->contact->address }}, {{ $invoice->contact->postal_code }} {{ $invoice->contact->city }}
            </p>
        </div>

        <div style="clear: both; margin-top: 10mm;">
            <p><strong>{{ __('Currency') }}</strong> {{ $invoice->currency }} &nbsp;&nbsp; <strong>{{ __('Amount') }}</strong> {{ number_format($invoice->total_amount, 2, '.', ' ') }}</p>
        </div>
    </div>
</div>
