<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $declaration->trader_declaration_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 15px; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { border: 1px solid #999; padding: 4px 5px; text-align: left; }
        th { background: #eee; width: 30%; }
        .sub { color: #555; font-weight: normal; }
    </style>
</head>
<body>
    <h1>{{ $labels['transit_title'][0] }}<br><span class="sub">{{ $labels['transit_title'][1] }}</span></h1>

    <table>
        <tr><th>{{ $labels['transit_document'][0] }} / {{ $labels['transit_document'][1] }}</th><td>{{ $declaration->transit_document_type->value }}</td></tr>
        <tr><th>{{ $labels['mrn'][0] }}</th><td>{{ $declaration->mrn ?? '-' }}</td></tr>
        <tr><th>{{ $labels['guarantee'][0] }} / {{ $labels['guarantee'][1] }}</th><td>{{ $declaration->transit_guarantee_reference ?? '-' }}</td></tr>
        <tr><th>{{ $labels['departure_office'][0] }} / {{ $labels['departure_office'][1] }}</th><td>{{ $declaration->transit_departure_office ?? '-' }}</td></tr>
        <tr><th>{{ $labels['destination_office'][0] }} / {{ $labels['destination_office'][1] }}</th><td>{{ $declaration->transit_destination_office ?? '-' }}</td></tr>
        <tr><th>{{ $labels['border'][0] }} / {{ $labels['border'][1] }}</th><td>{{ $declaration->border_crossing ?? '-' }}</td></tr>
        <tr><th>{{ $labels['deadline'][0] }} / {{ $labels['deadline'][1] }}</th><td>{{ $declaration->transit_deadline_on?->format('Y-m-d') ?? '-' }}</td></tr>
        <tr>
            <th>{{ $labels['discharge'][0] }} / {{ $labels['discharge'][1] }}</th>
            <td>{{ $labels[$dischargeStatus][0] }} / {{ $labels[$dischargeStatus][1] }}{{ $declaration->transit_discharged_at ? ' ('.$declaration->transit_discharged_at->format('Y-m-d').')' : '' }}</td>
        </tr>
    </table>
</body>
</html>
