<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>CU {{ $certification->year }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        h2 { font-size: 12px; margin: 14px 0 4px; border-bottom: 1px solid #999; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 4px; border: 1px solid #bbb; }
        td.label { width: 60%; background: #f3f3f3; }
        td.amount { text-align: right; }
    </style>
</head>
<body>
<h1>Certificazione Unica {{ $certification->year }}</h1>
<p>Redditi di lavoro autonomo, provvigioni e redditi diversi (art. 4 D.P.R. 322/1998)</p>

<h2>Sostituto d'imposta</h2>
<table>
    <tr><td class="label">Denominazione</td><td>{{ $tenant->legal_name }}</td></tr>
    <tr><td class="label">Codice fiscale</td><td>{{ $tenant->fiscal_code ?: $tenant->vat_number }}</td></tr>
    <tr><td class="label">Partita IVA</td><td>{{ $tenant->vat_number }}</td></tr>
</table>

<h2>Percipiente</h2>
<table>
    <tr><td class="label">Cognome e nome / denominazione</td><td>{{ $certification->recipient_name }}</td></tr>
    <tr><td class="label">Codice fiscale</td><td>{{ $certification->recipient_tax_code }}</td></tr>
    <tr><td class="label">Partita IVA</td><td>{{ $certification->recipient_vat_number }}</td></tr>
    <tr><td class="label">Causale</td><td>{{ $certification->reason_code }}</td></tr>
</table>

<h2>Dati fiscali</h2>
<table>
    <tr><td class="label">Ammontare lordo corrisposto</td><td class="amount">{{ number_format((float) $certification->gross_amount, 2, ',', '.') }}</td></tr>
    <tr><td class="label">Somme non soggette a ritenuta</td><td class="amount">{{ number_format((float) $certification->non_taxable_amount, 2, ',', '.') }}</td></tr>
    <tr><td class="label">Imponibile</td><td class="amount">{{ number_format((float) $certification->taxable_amount, 2, ',', '.') }}</td></tr>
    <tr><td class="label">Ritenute a titolo d'acconto</td><td class="amount">{{ number_format((float) $certification->withheld_amount, 2, ',', '.') }}</td></tr>
    <tr><td class="label">Contributi previdenziali a carico del percipiente</td><td class="amount">{{ number_format((float) $certification->contributions_amount, 2, ',', '.') }}</td></tr>
    <tr><td class="label">Numero di pagamenti</td><td class="amount">{{ $certification->payments_count }}</td></tr>
</table>

@if ($certification->issued_at)
    <p>Emessa il {{ $certification->issued_at->format('d/m/Y') }}.</p>
@endif
</body>
</html>
