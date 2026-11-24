@extends('documents.layouts.document-pdf')

@section('content')
    {{-- Header azienda e titolo documento --}}
    @include('documents.partials.header', ['document' => $invoice])

    {{-- Indirizzi mittente e destinatario --}}
    @include('documents.partials.recipient', ['document' => $invoice])

    {{-- Tabella righe documento --}}
    @include('documents.partials.lines-table', ['items' => $invoice->lines])

    {{-- Riepilogo totali e imposte --}}
    <table role="presentation" style="width: 100%; border-collapse: collapse; margin-top: 10px; page-break-inside: avoid;">
        <tr>
            <td style="width: 55%; vertical-align: top; padding-right: 20px;">
                @if(!empty($invoice->notes))
                    <div style="font-size: 8pt; color: #6b7280; font-weight: bold; text-transform: uppercase;">{{ __('Notes') }}</div>
                    <div style="font-size: 8.5pt; color: #374151; margin-top: 2px;">{!! nl2br(e($invoice->notes)) !!}</div>
                @endif
            </td>
            <td style="width: 45%; vertical-align: top;">
                <table role="presentation" style="width: 100%; border-collapse: collapse; font-size: 9pt;">
                    <tr>
                        <td style="padding: 4px 6px; text-align: left; color: #4b5563;">{{ __('Subtotal') }}:</td>
                        <td style="padding: 4px 6px; text-align: right;">{{ number_format($invoice->subtotal, 2, '.', "'") }} {{ $invoice->currency }}</td>
                    </tr>
                    @if(!empty($invoice->tax_amount) && $invoice->tax_amount > 0)
                        <tr>
                            <td style="padding: 4px 6px; text-align: left; color: #4b5563;">{{ __('VAT / Tax') }}:</td>
                            <td style="padding: 4px 6px; text-align: right;">{{ number_format($invoice->tax_amount, 2, '.', "'") }} {{ $invoice->currency }}</td>
                        </tr>
                    @endif
                    <tr style="border-top: 1.5px solid #111827; font-weight: bold; font-size: 10pt;">
                        <td style="padding: 6px; text-align: left; color: #111827;">{{ __('Total Due') }}:</td>
                        <td style="padding: 6px; text-align: right; color: #111827;">{{ number_format($invoice->total_amount, 2, '.', "'") }} {{ $invoice->currency }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Interruzione pagina per stampare la Swiss QR-Bill pulita nell'ultima pagina --}}
    <div class="page-break"></div>

    {{-- Sezione QR-Bill ufficiale --}}
    @include('documents.partials.swiss-qr-slip', [
        'invoice' => $invoice,
        'qrBillBase64' => $qrBillBase64
    ])
@endsection
