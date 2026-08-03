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

            // Step 13: the sentinel (§8 timeout toast + EC-02 re-login modal).
            // The email is the caller's OWN — the modal re-authenticates the
            // same person; there is no account chooser in a session-recovery
            // dialog.
            'sentinel' => [
                'email' => is_string($email = $user?->getAttribute('email')) ? $email : '',
                'copy' => [
                    'timeoutWarning' => __('auth.session.timeout_warning'),
                    'staySignedIn' => __('auth.session.stay_signed_in'),
                    'reloginTitle' => __('auth.session.relogin_title'),
                    'reloginIntro' => __('auth.session.relogin_intro'),
                    'passwordLabel' => __('auth.login.password_label'),
                    'showPassword' => __('auth.login.show_password'),
                    'hidePassword' => __('auth.login.hide_password'),
                    'submit' => __('auth.login.submit'),
                    'submitting' => __('auth.login.submitting'),
                    'errorFallback' => __('auth.login.error_fallback'),
                    'codeLabel' => __('auth.2fa.code_label'),
                    'useRecovery' => __('auth.2fa.use_recovery'),
                    'useTotp' => __('auth.2fa.use_totp'),
                    'recoveryLabel' => __('auth.2fa.recovery_label'),
                    'recoverySubmit' => __('auth.2fa.recovery_submit'),
                ],
            ],
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
                'twoFactorCodesWarning' => __('auth.2fa.codes_warning'),
                'twoFactorCodesSaved' => __('auth.2fa.codes_saved'),
                'twoFactorDownload' => __('auth.2fa.download_codes'),
                'twoFactorRegenerate' => __('auth.2fa.regenerate_codes'),
                'twoFactorDisable' => __('auth.2fa.disable'),
                'twoFactorDisableCodeLabel' => __('auth.2fa.disable_code_label'),

                // Step 13: §8's wizard chrome. The step headings are what §13's
                // focus rule lands on; the copy/copied pair feeds an aria-live
                // region so the state change is announced, not just repainted.
                'wizardScanTitle' => __('auth.2fa.wizard_scan_title'),
                'wizardVerifyTitle' => __('auth.2fa.wizard_verify_title'),
                'wizardCodesTitle' => __('auth.2fa.wizard_codes_title'),
                'wizardContinue' => __('auth.2fa.wizard_continue'),
                'wizardDone' => __('auth.2fa.wizard_done'),
                'copySecret' => __('auth.2fa.copy_secret'),
                'copyAllCodes' => __('auth.2fa.copy_all_codes'),
                'copied' => __('auth.2fa.copied'),
            ],
        ]);
    }
}
