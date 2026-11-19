@if ($cpq['show_details'])
    <table style="margin-top: 14px">
        <thead>
            <tr>
                <th>{{ __('pdf.columns.description') }}</th>
                <th class="right">{{ __('pdf.columns.quantity') }}</th>
                @if ($show_prices)
                    <th class="right">{{ __('pdf.columns.total') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($cpq['lines'] as $line)
                <tr>
                    <td>
                        {{ $line['description'] }}
                        @if ($line['is_optional'])
                            <em class="muted">({{ $line['is_selected'] ? __('pdf.quote.cpq_optional_selected') : __('pdf.quote.cpq_optional_available') }})</em>
                        @endif
                        @foreach ($line['specs'] as $spec)
                            <div class="muted">{{ $spec }}</div>
                        @endforeach
                    </td>
                    <td class="right">{{ rtrim(rtrim(number_format($line['quantity'], 4), '0'), '.') }}</td>
                    @if ($show_prices)
                        <td class="right">{{ number_format($line['line_total'], 2) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <table style="margin-top: 14px">
        <tbody>
            <tr>
                <td>{{ __('pdf.quote.cpq_package_price') }}</td>
                @if ($show_prices)
                    <td class="right">{{ number_format($cpq['package_total'], 2) }} {{ $currency }}</td>
                @endif
            </tr>
        </tbody>
    </table>
@endif
