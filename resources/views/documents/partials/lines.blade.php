<table style="margin-top: 14px">
    <thead>
        <tr>
            @if ($showType ?? false) <th>{{ __('pdf.columns.type') }}</th> @endif
            <th>{{ __('pdf.columns.description') }}</th>
            <th class="right">{{ __('pdf.columns.quantity') }}</th>
            @if ($show_prices)
                <th class="right">{{ __('pdf.columns.unit_price') }}</th>
                @if ($showDiscount ?? false) <th class="right">{{ __('pdf.columns.discount') }}</th> @endif
                <th class="right">{{ __('pdf.columns.tax') }}</th>
                <th class="right">{{ __('pdf.columns.total') }}</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                @if ($showType ?? false) <td>{{ __('pdf.line_types.'.($line['type'] ?? 'labor')) }}</td> @endif
                <td>{{ $line['description'] }}</td>
                <td class="right">{{ rtrim(rtrim(number_format($line['quantity'], 4), '0'), '.') }}</td>
                @if ($show_prices)
                    <td class="right">{{ number_format($line['unit_price'], 2) }}</td>
                    @if ($showDiscount ?? false) <td class="right">{{ number_format($line['discount_percentage'], 2) }}</td> @endif
                    <td class="right">{{ number_format($line['tax_rate'], 2) }}</td>
                    <td class="right">{{ number_format($line['line_total'], 2) }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>