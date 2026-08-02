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
        return Inertia::render('Security/Devices', [
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
            ],
        ]);
    }
}
