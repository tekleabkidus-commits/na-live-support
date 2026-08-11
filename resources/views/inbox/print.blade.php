<!DOCTYPE html>
{{-- §9 print (SEC-05, S07 step 10): a deliberately PLAIN server render —
     internal notes are excluded at the QUERY (the note gate; sabotage #20's
     target), the watermark carries user + DB timestamp on every page, and
     nothing interactive ships. C-08: every string is an i18n key. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('inbox.print.title') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; color: #1b1b18; }
        .watermark { position: fixed; inset-block-start: 0.25rem; inset-inline-end: 0.5rem; font-size: 10px; color: #6f6a61; }
        .msg { margin-block-end: 0.75rem; }
        .meta { font-size: 11px; color: #6f6a61; }
        .out { text-align: end; }
        @media print { .watermark { display: block; } }
    </style>
</head>
<body>
    <div class="watermark" data-testid="print-watermark">{{ $watermark }}</div>
    <h1 style="font-size: 16px;">{{ __('inbox.print.heading') }}</h1>
    <main data-testid="print-messages">
        @foreach($messages as $message)
            <div class="msg {{ $message->direction === 'out' ? 'out' : '' }}" data-kind="{{ $message->kind }}">
                <div class="meta">{{ $message->author_type }} · {{ $message->created_at }}</div>
                <div>{{ $message->kind === 'text' ? $message->body : __('inbox.print.media_omitted') }}</div>
            </div>
        @endforeach
    </main>
</body>
</html>
