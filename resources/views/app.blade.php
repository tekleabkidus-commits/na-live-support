<!DOCTYPE html>
{{-- §14/§13: the document language follows the request locale (user hop, or the
     guest cookie behind the login card's switcher) — assistive tech reads
     pronunciation from this attribute, so a hardcoded "en" would misread every
     Amharic render. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <title inertia>{{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
