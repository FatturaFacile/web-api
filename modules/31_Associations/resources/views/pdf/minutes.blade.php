<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $minutes->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2933; }
        h1 { font-size: 15px; }
        .body { white-space: pre-wrap; margin-top: 6mm; }
        .footer { margin-top: 10mm; color: #616e7c; font-size: 10px; }
    </style>
</head>
<body>
    <strong>{{ $tenant?->legal_name ?: $tenant?->name }}</strong>
    <h1>{{ $minutes->title }}@if ($minutes->number) — {{ $minutes->number }}@endif</h1>
    <div class="body">{{ $minutes->body }}</div>
    <div class="footer">
        @if ($minutes->approved_at)
            {{ __('association_governance.minutes_approved_on', ['date' => $minutes->approved_at->format('d/m/Y H:i')]) }} · {{ $minutes->content_hash }}
        @else
            {{ __('association_governance.minutes_draft') }}
        @endif
    </div>
</body>
</html>
