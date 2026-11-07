<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $book['title'] }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2933; }
        h1 { font-size: 14px; margin: 0 0 4mm; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd2d9; padding: 1.5mm; text-align: left; vertical-align: top; }
        th { background: #f0f4f8; }
        .meta { color: #616e7c; margin-bottom: 3mm; }
    </style>
</head>
<body>
    <strong>{{ $tenant?->legal_name ?: $tenant?->name }}</strong>
    <h1>{{ $book['title'] }}@if ($book['year']) — {{ $book['year'] }}@endif</h1>
    <div class="meta">{{ now()->format('d/m/Y H:i') }}</div>
    <table>
        <thead>
            <tr>
                @foreach ($book['headers'] as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($book['rows'] as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
