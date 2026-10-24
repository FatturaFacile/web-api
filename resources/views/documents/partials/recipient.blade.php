<table style="width: 100%; margin-bottom: 25px;">
    <tr>
        <td style="width: 50%; vertical-align: top;">
            {{-- Spazio sinistro opzionale per dettagli spedizione / magazzino se presenti --}}
            @if(!empty($document->shipping_address))
                <div style="font-size: 8pt; text-transform: uppercase; color: #6b7280; font-weight: bold; margin-bottom: 4px;">
                    {{ __('Shipping Address') }}
                </div>
                <div style="font-size: 9pt; color: #1f2937; line-height: 1.35;">
                    {!! nl2br(e($document->shipping_address)) !!}
                </div>
            @endif
        </td>
        <td style="width: 50%; vertical-align: top; padding-left: 20px;">
            <div style="font-size: 8pt; text-transform: uppercase; color: #6b7280; font-weight: bold; margin-bottom: 4px;">
                {{ __('Bill To') }}
            </div>
            <div style="font-size: 9.5pt; color: #111827; line-height: 1.4;">
                <strong>{{ $document->contact->name ?? $document->contact_name }}</strong><br>
                @if(!empty($document->contact->attention_to))
                    {{ __('Attn') }}: {{ $document->contact->attention_to }}<br>
                @endif
                {{ $document->contact->address ?? $document->contact_address }}<br>
                {{ $document->contact->postal_code ?? $document->contact_postal_code }} {{ $document->contact->city ?? $document->contact_city }}<br>
                {{ $document->contact->country ?? $document->contact_country }}<br>
                @if(!empty($document->contact->vat_number))
                    <strong>{{ __('VAT / Tax ID') }}:</strong> {{ $document->contact->vat_number }}<br>
                @endif
                @if(!empty($document->contact->fiscal_code) && $document->contact->fiscal_code !== $document->contact->vat_number)
                    <strong>{{ __('Fiscal Code') }}:</strong> {{ $document->contact->fiscal_code }}<br>
                @endif
            </div>
        </td>
    </tr>
</table>
