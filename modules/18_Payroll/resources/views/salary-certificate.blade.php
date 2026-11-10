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

    <h1>Certificato di salario (Modulo 11) {{ $certificate->year }}</h1>
    <p class="muted">
        Periodo {{ $certificate->period_from->format('d.m.Y') }} - {{ $certificate->period_to->format('d.m.Y') }}
        &nbsp;|&nbsp;
        F. Trasporto gratuito domicilio-lavoro / auto di servizio: <strong>{{ $certificate->checkbox_f_transport ? '[X]' : '[ ]' }}</strong>
        &nbsp;|&nbsp;
        G. Pasti alla mensa / buoni pasto: <strong>{{ $certificate->checkbox_g_canteen ? '[X]' : '[ ]' }}</strong>
    </p>

    <table>
        <tbody>
            <tr><td>1. Salario (mensile, orario, indennità, 13a mensilità)</td><td class="right">{{ $certificate->box1_salary }}</td></tr>
            <tr><td>2. Prestazioni salariali accessorie (vitto, alloggio, quota privata auto)</td><td class="right">{{ $certificate->box2_benefits }}</td></tr>
            <tr><td>3. Prestazioni non periodiche (bonus, gratifiche)</td><td class="right">{{ $certificate->box3_irregular }}</td></tr>
            <tr><td>4. Prestazioni in capitale</td><td class="right">{{ $certificate->box4_capital }}</td></tr>
            <tr><td>7. Altre prestazioni</td><td class="right">{{ $certificate->box7_other }}</td></tr>
            <tr class="total"><td>8. Salario lordo totale (1–7)</td><td class="right">{{ $certificate->box8_gross }}</td></tr>
            <tr><td>9. Contributi AVS/AI/IPG/AD/AINP</td><td class="right">{{ $certificate->box9_social_contributions }}</td></tr>
            <tr><td>10.1 Previdenza professionale (2° pilastro), contributi ordinari</td><td class="right">{{ $certificate->box10_1_ordinary_pension ?? $certificate->box10_pension }}</td></tr>
            <tr><td>10.2 Previdenza professionale (2° pilastro), contributi per il riscatto</td><td class="right">{{ $certificate->box10_2_pension_buyin ?? '0.00' }}</td></tr>
            <tr class="total"><td>11. Salario netto (8 meno 9 e 10)</td><td class="right">{{ $certificate->box11_net }}</td></tr>
            <tr><td>12. Ritenuta d'imposta alla fonte</td><td class="right">{{ $certificate->box12_withholding_tax }}</td></tr>
            <tr><td>13.1 Indennità per spese effettive (viaggio, vitto, alloggio, altre)</td><td class="right">{{ $certificate->box13_1_effective_expenses ?? $certificate->box13_expenses }}</td></tr>
            <tr><td>13.2 Indennità per spese forfettarie (rappresentanza, auto, altre)</td><td class="right">{{ $certificate->box13_2_lump_sum_expenses ?? '0.00' }}</td></tr>
            <tr><td>13. Totale indennità per spese</td><td class="right">{{ $certificate->box13_expenses }}</td></tr>
        </tbody>
    </table>

    @if($certificate->box15_remarks || $certificate->remarks)
        <h2>15. Osservazioni</h2>
        <p>{{ $certificate->box15_remarks ?? $certificate->remarks }}</p>
    @endif

    @if($certificate->barcode_svg)
        <div style="margin-top: 16px;">
            <p class="muted" style="font-size: 9px; margin-bottom: 4px;">Codice a barre 2D PDF417 (eCH-0019): {{ $certificate->barcode_payload }}</p>
            {!! $certificate->barcode_svg !!}
        </div>
    @endif

    <p class="muted">{{ $company['name'] }}, {{ $issueDate }}</p>
@endsection
