<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $declaration->trader_declaration_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 14px; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #999; padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        td.number, th.number { text-align: right; }
        .sub { color: #555; font-weight: normal; }
    </style>
</head>
<body>
    <h1>{{ $labels['packing_title'][0] }}<br><span class="sub">{{ $labels['packing_title'][1] }}</span></h1>

    <table>
        <tr>
            <th>{{ $labels['exporter'][0] }} / {{ $labels['exporter'][1] }}</th>
            <td>{{ $tenant->legal_name ?? $tenant->name }}<br>{{ $labels['identification'][0] }}: {{ $exporterIdentification ?? '-' }}</td>
            <th>{{ $labels['importer'][0] }} / {{ $labels['importer'][1] }}</th>
            <td>{{ $importerParty?->company_name ?? '-' }}<br>{{ $labels['identification'][0] }}: {{ $importerIdentification ?? '-' }}</td>
        </tr>
        <tr>
            <th>{{ $labels['invoice'][0] }} / {{ $labels['invoice'][1] }}</th>
            <td>{{ $invoice?->invoice_number ?? $declaration->trader_declaration_number }}</td>
            <th>{{ $labels['incoterms'][0] }} / {{ $labels['incoterms'][1] }}</th>
            <td>{{ $incoterm ?? '-' }}</td>
        </tr>
        <tr>
            <th>{{ $labels['mrn'][0] }}</th>
            <td colspan="3">{{ $declaration->mrn ?? '-' }}</td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>{{ $labels['description'][0] }} / {{ $labels['description'][1] }}</th>
                <th>{{ $labels['origin'][0] }} / {{ $labels['origin'][1] }}</th>
                <th>{{ $labels['packages'][0] }} / {{ $labels['packages'][1] }}</th>
                <th class="number">{{ $labels['net'][0] }} / {{ $labels['net'][1] }}</th>
                <th class="number">{{ $labels['gross'][0] }} / {{ $labels['gross'][1] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($declaration->items as $item)
                <tr>
                    <td>{{ $item->line_number }}</td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->origin_country }}</td>
                    <td>{{ $item->package_quantity }}</td>
                    <td class="number">{{ $item->net_mass }}</td>
                    <td class="number">{{ $item->gross_mass }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3">{{ $labels['total'][0] }} / {{ $labels['total'][1] }}</th>
                <th>{{ $declaration->total_packages }}</th>
                <th class="number">{{ $declaration->total_net_mass }}</th>
                <th class="number">{{ $declaration->total_gross_mass }}</th>
            </tr>
        </tfoot>
    </table>
</body>
</html>
