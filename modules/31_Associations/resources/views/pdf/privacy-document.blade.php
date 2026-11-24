<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $document['title'] }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; }
        h1 { font-size: 15px; }
        h2 { font-size: 12px; margin: 5mm 0 1mm; }
        .text { white-space: pre-wrap; }
        .template { border: 1px solid #d64545; color: #d64545; padding: 2mm; margin-bottom: 4mm; font-size: 9px; }
    </style>
</head>
<body>
    <div class="template">{{ __('association_privacy.template_warning') }}</div>
    <strong>{{ $tenant?->legal_name ?: $tenant?->name }}</strong>
    <h1>{{ $document['title'] }}</h1>
    @foreach ($document['sections'] as $section)
        <h2>{{ $section['heading'] }}</h2>
        <div class="text">{{ $section['text'] }}</div>
    @endforeach
</body>
</html>
