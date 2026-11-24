<table role="presentation" style="width: 100%; border: 1px solid #e5e7eb; border-radius: 4px; padding: 10px; font-size: 8.5pt; color: #374151; background-color: #fafafa; page-break-inside: avoid;">
    <tr>
        <td style="width: 60%; vertical-align: top;">
            <strong style="color: #111827; text-transform: uppercase; font-size: 8pt; display: block; margin-bottom: 4px;">
                {{ __('Payment Instructions') }}
            </strong>
            <table role="presentation" style="width: 100%; border-collapse: collapse;">
                @if(!empty($invoice->payment_terms ?? $invoice->paymentTerms?->name))
                    <tr>
                        <td style="width: 110px; padding: 2px 0; color: #6b7280;">{{ __('Payment Method') }}:</td>
                        <td style="padding: 2px 0; font-weight: bold;">{{ $invoice->payment_terms ?? $invoice->paymentTerms->name }}</td>
                    </tr>
                @endif
                @if(!empty($invoice->tenant->iban))
                    <tr>
                        <td style="padding: 2px 0; color: #6b7280;">{{ __('IBAN') }}:</td>
                        <td style="padding: 2px 0; font-weight: bold; font-family: monospace;">{{ chunk_split($invoice->tenant->iban, 4, ' ') }}</td>
                    </tr>
                @endif
                @if(!empty($invoice->tenant->bic_swift))
                    <tr>
                        <td style="padding: 2px 0; color: #6b7280;">{{ __('BIC / SWIFT') }}:</td>
                        <td style="padding: 2px 0;">{{ $invoice->tenant->bic_swift }}</td>
                    </tr>
                @endif
                @if(!empty($invoice->tenant->bank_name))
                    <tr>
                        <td style="padding: 2px 0; color: #6b7280;">{{ __('Bank') }}:</td>
                        <td style="padding: 2px 0;">{{ $invoice->tenant->bank_name }}</td>
                    </tr>
                @endif
            </table>
        </td>
        <td style="width: 40%; vertical-align: top; border-left: 1px solid #e5e7eb; padding-left: 12px;">
            <strong style="color: #111827; text-transform: uppercase; font-size: 8pt; display: block; margin-bottom: 4px;">
                {{ __('Payment Notes') }}
            </strong>
            <p style="margin: 0; line-height: 1.35; color: #4b5563;">
                {{ __('Please quote invoice number') }} <strong>{{ $invoice->number }}</strong> {{ __('in the payment reference/reason.') }}
            </p>
        </td>
    </tr>
</table>
