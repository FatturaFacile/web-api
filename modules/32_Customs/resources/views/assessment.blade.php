<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $decision->mrn }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 15px; margin: 0 0 4px; }
        .note { color: #555; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 4px; text-align: left; }
        th { background: #eee; width: 35%; }
    </style>
</head>
<body>
    <h1>Assessment decision {{ $decision->mrn }}</h1>
    <p class="note">Readable copy made from the archived XML for {{ $tenant->legal_name ?? $tenant->name }}. The signed XML is the original.</p>

    <table>
        <tr><th>Document type</th><td>{{ $decision->document_type->value }}</td></tr>
        <tr><th>Version</th><td>{{ $decision->version }}</td></tr>
        <tr><th>Document date</th><td>{{ $decision->document_date->toDateString() }}</td></tr>
        <tr><th>Direction</th><td>{{ $decision->direction }}</td></tr>
        <tr><th>Declaration number</th><td>{{ $decision->trader_declaration_number ?? '-' }}</td></tr>
        <tr><th>Duty</th><td>{{ $decision->duty_amount }}</td></tr>
        <tr><th>VAT</th><td>{{ $decision->vat_amount }}</td></tr>
        <tr><th>VAT base</th><td>{{ $decision->vat_base_amount }}</td></tr>
        <tr><th>Signature check</th><td>{{ $decision->signature_status->value }}</td></tr>
        <tr><th>XML SHA-256</th><td>{{ $decision->xml_sha256 }}</td></tr>
    </table>
</body>
</html>
