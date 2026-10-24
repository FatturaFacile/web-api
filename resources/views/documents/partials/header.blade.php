<table style="width: 100%; margin-bottom: 25px; border-bottom: 1px solid #e5e7eb; padding-bottom: 15px;">
    <tr>
        <td style="width: 50%; vertical-align: top;">
            @if(!empty($document->tenant->logo_url))
                <img src="{{ $document->tenant->logo_url }}" alt="{{ $document->tenant->name }}" style="max-height: 55px; max-width: 180px; margin-bottom: 8px;">
            @else
                <h2 style="margin: 0 0 5px 0; color: #111827; font-size: 16pt;">{{ $document->tenant->name }}</h2>
            @endif
            <div style="font-size: 8.5pt; color: #4b5563; line-height: 1.35;">
                {{ $document->tenant->address }}<br>
                {{ $document->tenant->postal_code }} {{ $document->tenant->city }} ({{ $document->tenant->country }})<br>
                @if(!empty($document->tenant->vat_number))
                    <strong>{{ __('VAT / Tax ID') }}:</strong> {{ $document->tenant->vat_number }}<br>
                @endif
                @if(!empty($document->tenant->email))
                    <strong>{{ __('Email') }}:</strong> {{ $document->tenant->email }}
                @endif
            </div>
        </td>
        <td style="width: 50%; vertical-align: top; text-align: right;">
            <h1 style="margin: 0 0 6px 0; font-size: 18pt; text-transform: uppercase; color: #1f2937; letter-spacing: 0.5px;">
                {{ $document->document_type_label ?? __('Invoice') }}
            </h1>
            <table style="margin-left: auto; font-size: 9pt; color: #374151; border-collapse: collapse;">
                <tr>
                    <td style="padding: 2px 8px; font-weight: bold; text-align: right;">{{ __('Number') }}:</td>
                    <td style="padding: 2px 0 2px 8px; text-align: right;">{{ $document->number ?? $document->document_number }}</td>
                </tr>
                <tr>
                    <td style="padding: 2px 8px; font-weight: bold; text-align: right;">{{ __('Date') }}:</td>
                    <td style="padding: 2px 0 2px 8px; text-align: right;">{{ optional($document->issue_date ?? $document->date)->format('d.m.Y') }}</td>
                </tr>
                @if(!empty($document->due_date))
                    <tr>
                        <td style="padding: 2px 8px; font-weight: bold; text-align: right;">{{ __('Due Date') }}:</td>
                        <td style="padding: 2px 0 2px 8px; text-align: right;">{{ optional($document->due_date)->format('d.m.Y') }}</td>
                    </tr>
                @endif
                @if(!empty($document->reference_number))
                    <tr>
                        <td style="padding: 2px 8px; font-weight: bold; text-align: right;">{{ __('Reference') }}:</td>
                        <td style="padding: 2px 0 2px 8px; text-align: right;">{{ $document->reference_number }}</td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>
