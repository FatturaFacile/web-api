<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('associations.pdf.card_title') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; }
        .card { width: 85.6mm; height: 54mm; border: 1px solid #52606d; border-radius: 3mm; padding: 4mm; }
        .tenant { font-size: 12px; font-weight: bold; }
        .label { color: #616e7c; font-size: 8px; text-transform: uppercase; }
        .qr { float: right; width: 26mm; height: 26mm; }
    </style>
</head>
<body>
    <div class="card">
        @if ($qr)
            <img class="qr" src="{{ $qr }}" alt="">
        @endif
        <div class="tenant">{{ $tenant?->name }}</div>
        <p><span class="label">{{ __('associations.pdf.member') }}</span><br>{{ $memberName }}</p>
        <p><span class="label">{{ __('associations.pdf.number') }}</span><br>{{ $membership->card_number }}</p>
        <p>
            <span class="label">{{ __('associations.pdf.type') }}</span> {{ $membership->membershipType->name }}<br>
            <span class="label">{{ __('associations.pdf.year') }}</span> {{ $membership->year->name }}<br>
            <span class="label">{{ __('associations.pdf.valid_until') }}</span> {{ $membership->valid_to->format('d/m/Y') }}
        </p>
    </div>
</body>
</html>
