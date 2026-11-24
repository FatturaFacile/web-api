<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Rendiconto IVA {{ $settlement->settlement_period }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border-bottom: 1px solid #ccc; padding: 5px 6px; text-align: left; }
        th.amount, td.amount { text-align: right; }
        .note { margin-top: 16px; color: #555; }
    </style>
</head>
<body>
    <h1>Rendiconto IVA - metodo effettivo</h1>
    <div>{{ $companyName }}@if ($uidNumber) - {{ $uidNumber }}@endif</div>
    <div>Periodo {{ $periodFrom }} - {{ $periodTo }} ({{ $settlement->currency }})</div>
    @unless ($settlement->isFinal())
        <div><strong>Bozza: rendiconto non ancora registrato.</strong></div>
    @endunless

    <table>
        <thead>
            <tr>
                <th>Cifra</th>
                <th>Voce</th>
                <th class="amount">Imponibile</th>
                <th class="amount">Aliquota</th>
                <th class="amount">Importo</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['box'] }}</td>
                    <td>{{ $row['label'] }}</td>
                    <td class="amount">{{ $row['taxable'] }}</td>
                    <td class="amount">{{ $row['rate'] !== null ? $row['rate'].'%' : '' }}</td>
                    <td class="amount">{{ $row['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="note">Documento di lavoro per la compilazione del rendiconto: non sostituisce l'inoltro all'AFC.</p>
</body>
</html>
