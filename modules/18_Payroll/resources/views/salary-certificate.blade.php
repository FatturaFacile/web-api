@extends('payroll::layout')

@section('title', 'Certificato di salario '.$certificate->year)

@section('content')
    <table class="parties">
        <tr>
            <td>
                <strong>{{ $company['name'] }}</strong><br>
                {{ $company['uid'] }}
            </td>
            <td>
                <strong>{{ $employee['name'] }}</strong><br>
                {{ $employee['address'] }}<br>
                {{ $employee['city'] }}<br>
                N. AVS {{ $employee['social_security_number'] }}<br>
                Data di nascita {{ $employee['date_of_birth'] }}
            </td>
        </tr>
    </table>

    <h1>Certificato di salario {{ $certificate->year }}</h1>
    <p class="muted">Periodo {{ $certificate->period_from->format('d.m.Y') }} - {{ $certificate->period_to->format('d.m.Y') }}. Non è il modulo ufficiale AFC con codice a barre.</p>

    <table>
        <tbody>
            <tr><td>1. Salario</td><td class="right">{{ $certificate->box1_salary }}</td></tr>
            <tr><td>2. Prestazioni salariali accessorie</td><td class="right">{{ $certificate->box2_benefits }}</td></tr>
            <tr><td>3. Prestazioni non periodiche</td><td class="right">{{ $certificate->box3_irregular }}</td></tr>
            <tr><td>4. Prestazioni in capitale</td><td class="right">{{ $certificate->box4_capital }}</td></tr>
            <tr><td>7. Altre prestazioni</td><td class="right">{{ $certificate->box7_other }}</td></tr>
            <tr class="total"><td>8. Salario lordo totale</td><td class="right">{{ $certificate->box8_gross }}</td></tr>
            <tr><td>9. Contributi AVS/AI/IPG/AD/AINP</td><td class="right">{{ $certificate->box9_social_contributions }}</td></tr>
            <tr><td>10.1 Previdenza professionale, contributi ordinari</td><td class="right">{{ $certificate->box10_pension }}</td></tr>
            <tr class="total"><td>11. Salario netto</td><td class="right">{{ $certificate->box11_net }}</td></tr>
            <tr><td>12. Imposta alla fonte trattenuta</td><td class="right">{{ $certificate->box12_withholding_tax }}</td></tr>
            <tr><td>13. Indennità per spese</td><td class="right">{{ $certificate->box13_expenses }}</td></tr>
        </tbody>
    </table>

    @if($certificate->remarks)
        <h2>Osservazioni</h2>
        <p>{{ $certificate->remarks }}</p>
    @endif

    <p class="muted">{{ $company['name'] }}, {{ $issueDate }}</p>
@endsection
