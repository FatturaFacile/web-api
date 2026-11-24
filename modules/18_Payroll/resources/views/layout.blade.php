<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #111; }
        h1 { font-size: 14pt; margin: 12px 0 4px; }
        h2 { font-size: 10.5pt; margin: 14px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 4px; text-align: left; vertical-align: top; }
        th { border-bottom: 1px solid #333; font-size: 8pt; }
        tr.total td { border-top: 1px solid #333; font-weight: bold; }
        .right { text-align: right; }
        .muted { color: #555; font-size: 8pt; }
        .parties td { width: 50%; }
    </style>
</head>
<body>
    @yield('content')
</body>
</html>
