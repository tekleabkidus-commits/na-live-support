<!DOCTYPE html>
{{-- lang is hardcoded to "en" only until the i18n layer lands in Session 1 (Doc 01 FR-09). --}}
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
