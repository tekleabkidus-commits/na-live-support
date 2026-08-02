<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Security page shell (Doc 02 FR-12, §8, §9).
 *
 * The page renders a table it does not own the data for: sessions come from
 * /api/staff/security/sessions on mount, so the web route needs only an
 * authenticated principal — no tenant middleware, no data fetch here. Copy is
 * passed as props, translated server-side where I-5 has already set the
 * request locale (C-08, N-03); the Vue layer contains no literal strings.
 */
final class SecurityPageController extends Controller
{
    public function show(): Response
    {
        $user = auth('staff')->user();

        return Inertia::render('Security/Devices', [
            // FR-06: the page needs to know which face of the 2FA card to show;
            // everything else about 2FA state stays server-side.
            'twoFactorEnabled' => $user?->getAttribute('two_factor_confirmed_at') !== null,
            'copy' => [
                'title' => __('auth.devices.title'),
                'subtitle' => __('auth.devices.subtitle'),
                'device' => __('auth.devices.column_device'),
                'firstSeen' => __('auth.devices.column_first_seen'),
                'lastActive' => __('auth.devices.column_last_active'),
                'ip' => __('auth.devices.column_ip'),
                'location' => __('auth.devices.column_location'),
                'currentBadge' => __('auth.devices.current_badge'),
                'locationUnavailable' => __('auth.devices.location_unavailable'),
                'revoke' => __('auth.devices.revoke'),
                'revokeConfirm' => __('auth.devices.revoke_confirm'),
                'revokeOthers' => __('auth.devices.revoke_others'),
                'revokeOthersTitle' => __('auth.devices.revoke_others_title'),
                'revokeOthersBody' => __('auth.devices.revoke_others_body'),
                'cancel' => __('auth.devices.cancel'),
                'onlyHere' => __('auth.devices.only_here'),
                'loadFailed' => __('auth.devices.load_failed'),

                'twoFactorTitle' => __('auth.2fa.title'),
                'twoFactorOn' => __('auth.2fa.status_on'),
                'twoFactorOff' => __('auth.2fa.status_off'),
                'twoFactorEnable' => __('auth.2fa.enable'),
                'twoFactorScan' => __('auth.2fa.scan_instructions'),
                'twoFactorManualSecret' => __('auth.2fa.manual_secret'),
                'twoFactorCodeLabel' => __('auth.2fa.code_label'),
                'twoFactorConfirm' => __('auth.2fa.confirm_action'),
                'twoFactorCodesWarning' => __('auth.2fa.codes_warning'),
                'twoFactorCodesSaved' => __('auth.2fa.codes_saved'),
                'twoFactorDownload' => __('auth.2fa.download_codes'),
                'twoFactorRegenerate' => __('auth.2fa.regenerate_codes'),
                'twoFactorDisable' => __('auth.2fa.disable'),
                'twoFactorDisableCodeLabel' => __('auth.2fa.disable_code_label'),
            ],
        ]);
    }
}
