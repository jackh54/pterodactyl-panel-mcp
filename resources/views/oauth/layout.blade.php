<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#0e0e10">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · {{ config('app.name', 'Pterodactyl') }}</title>
    @php($custom = (string) config('panel-mcp.brand_icon'))
    @php($icon = $custom !== '' ? $custom : '/favicons/apple-touch-icon.png')
    <link rel="icon" href="{{ $custom !== '' ? $custom : '/favicons/favicon-32x32.png' }}">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; }
        body {
            min-height: 100vh; background: #0e0e10; color: #d4d4d8;
            font: 15px/1.55 "IBM Plex Sans", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            display: flex; flex-direction: column; align-items: center; padding: 40px 16px;
        }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; color: #fafafa; text-decoration: none; }
        .brand img { width: 36px; height: 36px; border-radius: 8px; }
        .brand span { font: 600 18px 'Montserrat', system-ui, sans-serif; }
        .card { width: 100%; max-width: @yield('width', '460px'); background: #18181b; border: 1px solid #27272a; border-radius: 10px; padding: 26px; }
        h1 { font: 600 20px/1.3 'Montserrat', system-ui, sans-serif; color: #fafafa; margin: 0 0 6px; overflow-wrap: anywhere; }
        p { margin: 0 0 14px; }
        .muted { color: #a1a1aa; font-size: 13.5px; }
        .note { background: #111113; border: 1px solid #27272a; border-radius: 8px; padding: 11px 13px; font-size: 13.5px; margin-bottom: 16px; overflow-wrap: anywhere; }
        .note strong { color: #fafafa; }
        .warn { border-color: #854d0e; background: #1c1508; color: #fde68a; }
        .ok { border-color: #166534; background: #0a1a10; color: #bbf7d0; }
        label.scope { display: flex; gap: 12px; align-items: flex-start; padding: 12px 13px; border: 1px solid #27272a; border-radius: 8px; margin-bottom: 9px; cursor: pointer; }
        label.scope:has(input:checked) { border-color: #2563eb; background: #111827; }
        label.scope input { margin-top: 4px; width: 16px; height: 16px; accent-color: #2563eb; flex: none; }
        label.scope b { display: block; color: #fafafa; font-weight: 600; }
        label.scope span { color: #a1a1aa; font-size: 13.5px; }
        .actions { display: flex; gap: 10px; margin-top: 18px; }
        button, .button {
            font: 600 14px system-ui, sans-serif; border-radius: 7px; padding: 10px 16px; cursor: pointer;
            border: 1px solid #3f3f46; background: #27272a; color: #fafafa; text-decoration: none; text-align: center;
        }
        button:hover, .button:hover { background: #3f3f46; }
        button.primary { background: #2563eb; border-color: #2563eb; flex: 1; }
        button.primary:hover { background: #1d4ed8; }
        button.danger { background: transparent; border-color: #7f1d1d; color: #fca5a5; padding: 6px 12px; font-size: 13px; }
        button.danger:hover { background: #450a0a; }
        button:focus-visible, .button:focus-visible, input:focus-visible { outline: 2px solid #60a5fa; outline-offset: 2px; }
        code { background: #111113; border: 1px solid #27272a; border-radius: 5px; padding: 2px 6px; font-size: 13px; color: #fafafa; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #71717a; font-weight: 600; padding: 0 10px 8px 0; }
        td { padding: 12px 10px 12px 0; border-top: 1px solid #27272a; vertical-align: top; font-size: 14px; }
        td:last-child, th:last-child { padding-right: 0; text-align: right; }
        td b { color: #fafafa; }
        .tag { display: inline-block; font-size: 12px; border: 1px solid #3f3f46; border-radius: 99px; padding: 1px 8px; margin: 0 4px 4px 0; color: #d4d4d8; }
        .foot { margin-top: 18px; font-size: 13px; color: #71717a; }
        .foot a { color: #93c5fd; }
        @media (max-width: 560px) {
            .card { padding: 20px; }
            table, tbody, tr, td { display: block; }
            thead { display: none; }
            tr { border-top: 1px solid #27272a; padding: 12px 0; }
            td { border: 0; padding: 2px 0; }
            td:last-child { text-align: left; padding-top: 8px; }
        }
    </style>
</head>
<body>
    <a class="brand" href="/"><img src="{{ $icon }}" alt="" onerror="this.remove()"><span>{{ config('app.name', 'Pterodactyl') }}</span></a>
    <main class="card">
        @yield('content')
    </main>
    @yield('below')
</body>
</html>
