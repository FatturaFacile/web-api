<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('association_finance.statement_title', ['year' => $filing->year]) }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2933; }
        h1 { font-size: 14px; }
        h2 { font-size: 11px; margin-top: 6mm; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e4e7eb; padding: 1mm 1.5mm; text-align: left; }
        td.amount, th.amount { text-align: right; width: 22mm; }
        .meta { color: #616e7c; }
    </style>
</head>
<body>
    <strong>{{ $tenant?->legal_name ?: $tenant?->name }}</strong>
    <h1>{{ __('association_finance.statement_title', ['year' => $filing->year]) }}</h1>
    <div class="meta">{{ $filing->snapshot['source'] }} · {{ $filing->content_hash }}</div>
    @foreach ($filing->snapshot['statements'] as $name => $statement)
        <h2>{{ __('association_finance.statements.'.$name) }}</h2>
        <table>
            @if ($name === 'mission_report')
                @foreach ($statement as $point)
                    <tr><td>{{ $point['point'] }}. {{ $point['heading'] }}</td><td>{{ $point['text'] }}</td></tr>
                @endforeach
            @else
                <tr><th>{{ __('association_finance.line') }}</th><th></th><th class="amount">{{ $filing->year }}</th><th class="amount">{{ $filing->year - 1 }}</th></tr>
                @foreach ($statement['lines'] as $line)
                    <tr><td>{{ $line['code'] }}</td><td>{{ $line['label'] }}</td><td class="amount">{{ $line['current'] }}</td><td class="amount">{{ $line['previous'] }}</td></tr>
                @endforeach
            @endif
        </table>
    @endforeach
</body>
</html>
