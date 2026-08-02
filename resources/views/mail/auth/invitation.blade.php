{{-- Doc 02 FR-17 invitation email. Same rules as reset.blade.php: i18n keys
     only, locale pinned at dispatch, workspace name as data (N-03). --}}
<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1a1a1a; max-inline-size: 36em; margin-inline: auto; padding: 1.5em;">
    <p>{{ __('auth.mail.invite_line1', ['workspace' => $workspaceName]) }}</p>

    <p style="margin-block: 2em;">
        <a href="{{ $inviteUrl }}"
           style="background: #1a1a1a; color: #ffffff; padding: 0.75em 1.5em; text-decoration: none; border-radius: 4px;">
            {{ __('auth.mail.invite_action') }}
        </a>
    </p>

    <p>{{ __('auth.mail.invite_valid', ['days' => $validDays]) }}</p>

    <p>{{ __('auth.mail.invite_ignore') }}</p>

    <p style="color: #6a6a6a; font-size: 0.85em; margin-block-start: 2em;">
        {{ __('auth.mail.link_fallback') }}<br>
        <span style="word-break: break-all;">{{ $inviteUrl }}</span>
    </p>
</body>
</html>
