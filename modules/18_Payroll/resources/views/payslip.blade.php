@extends('payroll::layout')

@section('title', 'Busta paga '.$period)

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
                {{ $employee['city'] }}
            </td>
        </tr>
    </table>

    <h1>Busta paga {{ $period }}</h1>
    <p class="muted">
        N. {{ $employee['number'] }} - N. AVS / codice fiscale {{ $employee['social_security_number'] }}
        - Giorni pagati {{ $paidDays }}
        @if($paymentDate) - Data di pagamento {{ $paymentDate }} @endif
        @if($payslip->withholding_tax_code) - Imposta alla fonte {{ $payslip->withholding_tax_canton }} {{ $payslip->withholding_tax_code }} {{ $payslip->withholding_tax_rate_percent }}% @endif
    </p>

    <table>
        <thead>
            <tr><th>Cod.</th><th>Descrizione</th><th class="right">Base / Q.tà</th><th class="right">Aliquota</th><th class="right">Importo</th></tr>
        </thead>
        <tbody>
            @foreach($lines as $line)
                <tr>
                    <td>{{ $line['code'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="right">{{ $line['quantity'] }}</td>
                    <td class="right">{{ $line['rate'] }}</td>
                    <td class="right">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="4">Salario lordo</td><td class="right">{{ $payslip->gross_salary }}</td></tr>
            <tr class="total"><td colspan="4">Salario netto</td><td class="right">{{ $payslip->net_salary }}</td></tr>
            <tr class="total"><td colspan="4">Importo versato</td><td class="right">{{ $payslip->payout_amount }}</td></tr>
        </tbody>
    </table>

    @if($employee['iban'])
        <p class="muted">IBAN {{ $employee['iban'] }}</p>
    @endif

    <h2>Contributi del datore di lavoro</h2>
    <table>
        <thead>
            <tr><th>Cod.</th><th>Descrizione</th><th class="right">Base / Q.tà</th><th class="right">Aliquota</th><th class="right">Importo</th></tr>
        </thead>
        <tbody>
            @foreach($employerLines as $line)
                <tr>
                    <td>{{ $line['code'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="right">{{ $line['quantity'] }}</td>
                    <td class="right">{{ $line['rate'] }}</td>
                    <td class="right">{{ $line['amount'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
