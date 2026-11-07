<!DOCTYPE html>
<html lang="{{ $declaration->language }}">
<head>
    <meta charset="utf-8">
    <title>{{ $declaration->trader_declaration_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 15px; margin: 0 0 4px; }
        .note { color: #555; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #999; padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        td.number, th.number { text-align: right; }
    </style>
</head>
<body>
    <h1>{{ ucfirst($declaration->declaration_type->value) }} declaration {{ $declaration->trader_declaration_number }}</h1>
    <p class="note">Internal copy from {{ $tenant->legal_name ?? $tenant->name }}. This is not a customs document.</p>

    <table>
        <tr><th>Status</th><td>{{ $declaration->status->value }}</td><th>MRN</th><td>{{ $declaration->mrn ?? '-' }}{{ $declaration->mrn_version ? ' / '.$declaration->mrn_version : '' }}</td></tr>
        <tr><th>Dispatch country</th><td>{{ $declaration->dispatch_country ?? '-' }}</td><th>Destination country</th><td>{{ $declaration->destination_country ?? '-' }}</td></tr>
        <tr><th>Consignor</th><td>{{ $declaration->consignor?->company_name ?? '-' }}</td><th>Consignee</th><td>{{ $declaration->consignee?->company_name ?? '-' }}</td></tr>
        <tr><th>Customs office</th><td>{{ $declaration->customs_office_number ?? '-' }}</td><th>Incoterms</th><td>{{ $declaration->incoterms ?? '-' }}</td></tr>
        <tr><th>Transport</th><td>{{ $declaration->transport_mode ?? '-' }} {{ $declaration->transportation_number }}</td><th>Reference</th><td>{{ $declaration->trader_reference ?? '-' }}</td></tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>#</th><th>Description</th><th>Tariff no.</th><th>Key</th><th>Origin</th><th>Packages</th>
                <th class="number">Gross kg</th><th class="number">Net kg</th><th class="number">Stat. value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($declaration->items as $item)
                <tr>
                    <td>{{ $item->line_number }}</td>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->commodity_code }}</td>
                    <td>{{ $item->statistical_code }}</td>
                    <td>{{ $item->origin_country }}</td>
                    <td>{{ $item->package_quantity }} {{ $item->packaging_type }}</td>
                    <td class="number">{{ $item->gross_mass }}</td>
                    <td class="number">{{ $item->net_mass }}</td>
                    <td class="number">{{ $item->statistical_value }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5">Totals</th>
                <th>{{ $declaration->total_packages }}</th>
                <th class="number">{{ $declaration->total_gross_mass }}</th>
                <th class="number">{{ $declaration->total_net_mass }}</th>
                <th class="number">{{ $declaration->total_statistical_value }}</th>
            </tr>
        </tfoot>
    </table>
</body>
</html>
