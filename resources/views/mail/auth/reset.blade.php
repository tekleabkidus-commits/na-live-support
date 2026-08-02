{{-- Doc 02 FR-16 reset email. Every string is an i18n key (N-03/C-08); the
     recipient's locale was pinned at dispatch. Deliberately spartan HTML —
     tenant-branded mail theming is Doc 16's; the workspace NAME is the one
     branding element FR-16 needs now, and it arrives as data, never copy. --}}
<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a; max-inline-size: 36em; margin-inline: auto; padding: 1.5em;">
    <p>{{ __('auth.mail.reset_line1', ['workspace' => $workspaceName]) }}</p>

    <p style="margin-block: 2em;">
        <a href="{{ $resetUrl }}"
           style="background: #1a1a1a; color: #ffffff; padding: 0.75em 1.5em; text-decoration: none; border-radius: 4px;">
            {{ __('auth.mail.reset_action') }}
        </a>
    </p>

    <p>{{ __('auth.mail.reset_valid', ['minutes' => $validMinutes]) }}</p>

    <p>{{ __('auth.mail.reset_ignore') }}</p>

    <p style="color: #6a6a6a; font-size: 0.85em; margin-block-start: 2em;">
        {{ __('auth.mail.link_fallback') }}<br>
        <span style="word-break: break-all;">{{ $resetUrl }}</span>
    </p>
</body>
</html>
