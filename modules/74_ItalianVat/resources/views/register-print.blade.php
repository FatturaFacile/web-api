<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $section->name }} {{ $print->year }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 13px; margin: 0 0 2px; }
        .meta { margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 2px 4px; }
        th { background: #eee; text-align: left; }
        .num { text-align: right; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .footer { margin-top: 6px; font-size: 8px; color: #444; }
    </style>
</head>
<body>
@foreach ($pages as $index => $lines)
    @php($pageNumber = $print->page_from + $index)
    <div class="page">
        <h1>{{ $tenant->legal_name }} - P.IVA {{ $tenant->vat_number }}</h1>
        <div class="meta">
            {{ $section->name }} ({{ $section->code }}) - {{ $print->year }}
            - {{ $print->period_from->toDateString() }} / {{ $print->period_to->toDateString() }}
        </div>
        <table>
            <thead>
            <tr>
                <th>Prot.</th>
                <th>Data</th>
                <th>Documento</th>
                <th>Descrizione</th>
                <th class="num">Imponibile</th>
                <th class="num">Aliq.</th>
                <th class="num">IVA</th>
                <th class="num">IVA detraibile</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['protocol'] }}</td>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $line['document_number'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $line['taxable'] }}</td>
                    <td class="num">{{ $line['rate'] }}</td>
                    <td class="num">{{ $line['vat'] }}</td>
                    <td class="num">{{ $line['deductible_vat'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="footer">
            {{ $tenant->legal_name }} - {{ $section->code }}/{{ $print->year }} - pag. {{ $pageNumber }}
            - {{ $print->stamp_reference }} - SHA-256 {{ $print->content_hash }}
        </div>
    </div>
@endforeach
</body>
</html>
