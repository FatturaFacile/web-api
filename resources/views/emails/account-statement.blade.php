<p>{{ __('emails.greeting', ['name' => $contact->company_name ?: trim($contact->first_name.' '.$contact->last_name)]) }}</p>

<p>{{ __('emails.account_statement.intro', ['date' => $statement['as_of']]) }}</p>

@if (count($statement['lines']) === 0)
    <p>{{ __('emails.account_statement.no_open_items') }}</p>
@else
    <table>
        <thead>
            <tr>
                <th>{{ __('emails.account_statement.columns.invoice') }}</th>
                <th>{{ __('emails.account_statement.columns.due_date') }}</th>
                <th>{{ __('emails.account_statement.columns.balance') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statement['lines'] as $line)
                <tr>
                    <td>{{ $line['invoice_number'] }}</td>
                    <td>{{ $line['due_date'] }}</td>
                    <td>{{ number_format($line['balance'], 2) }} {{ $line['currency'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p>{{ __('emails.account_statement.total_due') }}: {{ number_format($statement['total_due'], 2) }}</p>
    <p>{{ __('emails.account_statement.overdue') }}: {{ number_format($statement['overdue_total'], 2) }}</p>
@endif

<p>{{ __('emails.closing') }}</p>