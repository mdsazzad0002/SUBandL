<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Subscription') — {{ config('app.name') }}</title>
    {{-- Same stylesheet the Vue and React pages import. --}}
    <style>{!! \SUBandL\Support\Assets::inline('subandl.css') !!}</style>
    <style>
        body { margin: 0; background: #f5f6f8; }
        @media (prefers-color-scheme: dark) { body { background: #0f1115; } }
    </style>
</head>
<body>
<div class="subandl subandl-auto-dark">
    @yield('content')
</div>
@stack('scripts')
</body>
</html>
