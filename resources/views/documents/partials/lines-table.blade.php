<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 8.5pt;">
    <thead>
        <tr style="background-color: #f3f4f6; border-top: 1px solid #d1d5db; border-bottom: 1px solid #d1d5db;">
            <th style="padding: 7px 6px; text-align: left; width: 5%;">#</th>
            <th style="padding: 7px 6px; text-align: left; width: 45%;">{{ __('Description') }}</th>
            <th style="padding: 7px 6px; text-align: right; width: 10%;">{{ __('Qty') }}</th>
            <th style="padding: 7px 6px; text-align: right; width: 12%;">{{ __('Unit Price') }}</th>
            <th style="padding: 7px 6px; text-align: right; width: 10%;">{{ __('VAT') }}</th>
            <th style="padding: 7px 6px; text-align: right; width: 18%;">{{ __('Total') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($items as $index => $line)
            <tr style="border-bottom: 1px solid #e5e7eb; page-break-inside: avoid;">
                <td style="padding: 7px 6px; vertical-align: top; color: #6b7280;">{{ $index + 1 }}</td>
                <td style="padding: 7px 6px; vertical-align: top;">
                    <strong style="color: #111827;">{{ $line->name ?? $line->description }}</strong>
                    @if(!empty($line->description) && $line->description !== $line->name)
                        <div style="color: #6b7280; font-size: 8pt; margin-top: 2px;">{!! nl2br(e($line->description)) !!}</div>
                    @endif
                    @if(!empty($line->sku))
                        <div style="color: #9ca3af; font-size: 7.5pt; margin-top: 1px;">{{ __('SKU') }}: {{ $line->sku }}</div>
                    @endif
                </td>
                <td style="padding: 7px 6px; vertical-align: top; text-align: right;">
                    {{ number_format($line->quantity, 2, '.', '') }}
                    <span style="font-size: 7.5pt; color: #6b7280;">{{ $line->unit ?? '' }}</span>
                </td>
                <td style="padding: 7px 6px; vertical-align: top; text-align: right;">
                    {{ number_format($line->unit_price, 2, '.', "'") }}
                </td>
                <td style="padding: 7px 6px; vertical-align: top; text-align: right;">
                    {{ number_format($line->tax_rate ?? $line->vat_rate ?? 0, 1) }}%
                </td>
                <td style="padding: 7px 6px; vertical-align: top; text-align: right; font-weight: bold; color: #111827;">
                    {{ number_format($line->total_amount ?? ($line->quantity * $line->unit_price), 2, '.', "'") }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" style="padding: 15px; text-align: center; color: #9ca3af;">
                    {{ __('No line items found.') }}
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
