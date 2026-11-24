<p>Buongiorno {{ $employeeName }},</p>

<p>il piano turni dal {{ $periodFrom }} al {{ $periodTo }} è stato pubblicato.</p>

<table cellpadding="4">
    @foreach ($lines as $line)
        <tr>
            <td>{{ $line['date'] }}</td>
            <td>{{ $line['name'] }}</td>
            <td>{{ $line['time'] }}</td>
        </tr>
    @endforeach
</table>
