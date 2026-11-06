@extends('payroll::layout')

@section('title', 'Libro Unico del Lavoro '.$period)

@section('content')
    <h1>Libro Unico del Lavoro - {{ $period }}</h1>
    <p class="muted">{{ $company['name'] }} - P.IVA {{ $company['uid'] }} - Matricola INPS {{ $inpsCompanyNumber }} - INAIL {{ $inailCode }}</p>

    <h2>Sezione paga</h2>
    <table>
        <thead>
            <tr>
                <th>Lavoratore</th><th>Codice fiscale</th><th>CCNL / livello</th><th class="right">Giorni</th><th class="right">Lordo</th>
                <th class="right">INPS dip.</th><th class="right">IRPEF</th><th class="right">Netto</th><th class="right">INPS azienda</th><th class="right">TFR</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payslips as $row)
                <tr>
                    <td>{{ $row['employee'] }}</td>
                    <td>{{ $row['tax_code'] }}</td>
                    <td>{{ $row['contract'] }}</td>
                    <td class="right">{{ $row['days'] }}</td>
                    <td class="right">{{ $row['gross'] }}</td>
                    <td class="right">{{ $row['inps'] }}</td>
                    <td class="right">{{ $row['irpef'] }}</td>
                    <td class="right">{{ $row['net'] }}</td>
                    <td class="right">{{ $row['employer_inps'] }}</td>
                    <td class="right">{{ $row['tfr'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Dettaglio delle voci</h2>
    <table>
        <thead>
            <tr><th>Lavoratore</th><th>Codice</th><th>Voce</th><th class="right">Quantità / base</th><th class="right">Tasso</th><th class="right">Importo</th></tr>
        </thead>
        <tbody>
            @foreach($lines as $row)
                <tr>
                    <td>{{ $row['employee'] }}</td>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['description'] }}</td>
                    <td class="right">{{ $row['quantity'] }}</td>
                    <td class="right">{{ $row['rate'] }}</td>
                    <td class="right">{{ $row['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Sezione presenze</h2>
    <table>
        <thead>
            <tr><th>Lavoratore</th><th>Giorno</th><th class="right">Ore lavorate</th><th class="right">Ore eccedenti</th><th>Assenza</th></tr>
        </thead>
        <tbody>
            @foreach($attendance as $row)
                <tr>
                    <td>{{ $row['employee'] }}</td>
                    <td>{{ $row['date'] }}</td>
                    <td class="right">{{ $row['worked'] }}</td>
                    <td class="right">{{ $row['overtime'] }}</td>
                    <td>{{ $row['absence'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="muted">Stampato il {{ $printDate }} - Libro unico del lavoro, art. 39 D.L. 112/2008.</p>
@endsection
