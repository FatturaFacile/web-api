<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $meeting->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1f2933; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        .recipient { margin: 10mm 0 10mm 90mm; }
        h1 { font-size: 15px; }
        ol { margin-top: 2mm; }
    </style>
</head>
<body>
@foreach ($participants as $participant)
    <div class="page">
        <strong>{{ $tenant?->legal_name ?: $tenant?->name }}</strong>
        <div class="recipient">{{ $participant->member->displayName() }}</div>
        <h1>{{ __('association_governance.notice_intro', ['body' => $meeting->bodyLabel()]) }}</h1>
        <p>{{ $meeting->title }}</p>
        <p>{{ __('association_governance.notice_when', ['date' => $meeting->scheduled_at?->format('d/m/Y H:i') ?? '-']) }}</p>
        @if ($meeting->second_call_at)
            <p>{{ __('association_governance.notice_second_call', ['date' => $meeting->second_call_at->format('d/m/Y H:i')]) }}</p>
        @endif
        @if ($meeting->location)
            <p>{{ __('association_governance.notice_where', ['location' => $meeting->location]) }}</p>
        @endif
        @if ($meeting->subject)
            <p>{{ $meeting->subject }}</p>
        @endif
        <strong>{{ __('association_governance.agenda') }}</strong>
        <ol>
            @foreach ($meeting->agendaItems as $item)
                <li>{{ $item->title }}</li>
            @endforeach
        </ol>
    </div>
@endforeach
</body>
</html>
