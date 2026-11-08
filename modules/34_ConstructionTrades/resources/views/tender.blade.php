<!DOCTYPE html>
<html lang="{{ $bid->language }}">
<head>
    <meta charset="utf-8">
    <title>{{ $bid->bid_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h1 { font-size: 15px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #999; padding: 4px; vertical-align: top; }
        th { text-align: left; background: #eee; }
        .num { text-align: right; white-space: nowrap; }
        .blank { border-bottom: 1px dotted #333; min-width: 60px; display: inline-block; }
    </style>
</head>
<body>
@php
    /* Amounts are decimal strings: round with bcmath and group the thousands, never through a float. */
    $money = static fn (mixed $amount): string => preg_replace('/\B(?=(\d{3})+(?!\d))/', "'", Modules\GeneralLedger\Services\Tax\Decimal::round2((string) $amount)) ?? '';
@endphp
    <p>{{ $tenant?->legal_name ?? $tenant?->name }}</p>
    <h1>{{ $bid->title }}</h1>
    <p>{{ $bid->bid_number }} &middot; {{ $bid->contact?->company_name ?? trim(($bid->contact?->first_name ?? '').' '.($bid->contact?->last_name ?? '')) }} &middot; {{ $bid->currency }}</p>

    <table>
        <thead>
            <tr>
                <th>Pos.</th>
                <th>&nbsp;</th>
                <th class="num">Qty</th>
                <th>Unit</th>
                <th class="num">Price</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bid->positions as $position)
                <tr>
                    <td>{{ $position->position_code }}</td>
                    <td>
                        {{ $position->description }}
                        @foreach ($position->components ?? [] as $component)
                            <br>&ndash; {{ $component['description'] ?? '' }}
                        @endforeach
                    </td>
                    <td class="num">{{ rtrim(rtrim((string) $position->quantity, '0'), '.') }}</td>
                    <td>{{ $position->unit }}</td>
                    @if ($withPrices)
                        <td class="num">{{ $money($position->unit_price) }}</td>
                        <td class="num">{{ $money($position->total) }}</td>
                    @else
                        <td class="num"><span class="blank">&nbsp;</span></td>
                        <td class="num"><span class="blank">&nbsp;</span></td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        @if ($withPrices)
            <tfoot>
                <tr>
                    <td colspan="5" class="num">{{ $bid->currency }} (net)</td>
                    <td class="num">{{ $money($netTotal) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
