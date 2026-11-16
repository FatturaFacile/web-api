<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('practice.pdf.proposal_title') }} {{ $proposal->number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .tenant { font-size: 12px; font-weight: bold; }
        .label { color: #616e7c; font-size: 9px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        td { padding: 5px 4px; border-bottom: 1px solid #d9e2ec; vertical-align: top; }
        .total td { font-weight: bold; border-top: 2px solid #1f2933; }
        .note { margin-top: 18px; color: #616e7c; font-size: 9px; }
    </style>
</head>
<body>
    <div class="tenant">{{ $tenant?->name }}</div>
    <h1>{{ __('practice.pdf.proposal_title') }} {{ $proposal->number }}</h1>
    <table>
        <tr><td class="label">{{ __('practice.pdf.client') }}</td><td>{{ $clientName }}</td></tr>
        <tr><td class="label">{{ __('practice.pdf.subject') }}</td><td>{{ $proposal->title }}</td></tr>
        @if ($proposal->scope)
            <tr><td class="label">{{ __('practice.pdf.scope') }}</td><td>{!! nl2br(e($proposal->scope)) !!}</td></tr>
        @endif
        <tr><td class="label">{{ __('practice.pdf.fee_basis') }}</td><td>{{ $proposal->fee_type === 'hourly' ? __('practice.pdf.fee_hourly') : __('practice.pdf.fee_fixed') }}</td></tr>
        @if ($proposal->fee_type === 'hourly')
            <tr><td class="label">{{ __('practice.pdf.hourly_rate') }}</td><td>{{ $proposal->hourly_rate }}</td></tr>
            <tr><td class="label">{{ __('practice.pdf.estimated_hours') }}</td><td>{{ $proposal->estimated_hours }}</td></tr>
        @else
            <tr><td class="label">{{ __('practice.pdf.fixed_fee') }}</td><td>{{ $proposal->fixed_fee }}</td></tr>
        @endif
        <tr class="total"><td>{{ __('practice.pdf.estimated_total') }}</td><td>{{ $proposal->estimated_total }}</td></tr>
        @if ($proposal->valid_until)
            <tr><td class="label">{{ __('practice.pdf.valid_until') }}</td><td>{{ $proposal->valid_until->format('d/m/Y') }}</td></tr>
        @endif
        <tr><td class="label">{{ __('practice.pdf.status') }}</td><td>{{ __('practice.pdf.status_'.$proposal->status) }}</td></tr>
    </table>
    <p class="note">{{ __('practice.pdf.notes') }}</p>
</body>
</html>
