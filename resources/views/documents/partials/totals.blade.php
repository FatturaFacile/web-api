@if ($totals)
    <table class="totals" role="presentation">
        <tr><td>{{ __('pdf.totals.net') }}</td><td class="right">{{ number_format($totals['net'], 2) }} {{ $currency }}</td></tr>
        <tr><td>{{ __('pdf.totals.tax') }}</td><td class="right">{{ number_format($totals['tax'], 2) }} {{ $currency }}</td></tr>
        @if (($totals['stamp_duty'] ?? 0) > 0)
            <tr><td>{{ __('pdf.totals.stamp_duty') }}</td><td class="right">{{ number_format($totals['stamp_duty'], 2) }} {{ $currency }}</td></tr>
        @endif
        @if (($totals['rounding'] ?? 0) != 0)
            <tr><td>{{ __('pdf.totals.rounding') }}</td><td class="right">{{ number_format($totals['rounding'], 2) }} {{ $currency }}</td></tr>
        @endif
        <tr class="grand"><td>{{ __('pdf.totals.gross') }}</td><td class="right">{{ number_format($totals['gross'], 2) }} {{ $currency }}</td></tr>
        @if (($totals['withholding_tax'] ?? 0) > 0)
            <tr><td>{{ __('pdf.totals.withholding_tax') }}</td><td class="right">- {{ number_format($totals['withholding_tax'], 2) }} {{ $currency }}</td></tr>
        @endif
        @if (($totals['paid'] ?? 0) > 0)
            <tr><td>{{ __('pdf.totals.paid') }}</td><td class="right">- {{ number_format($totals['paid'], 2) }} {{ $currency }}</td></tr>
            <tr><td><strong>{{ __('pdf.totals.balance') }}</strong></td><td class="right"><strong>{{ number_format($totals['balance'], 2) }} {{ $currency }}</strong></td></tr>
        @endif
    </table>
@endif