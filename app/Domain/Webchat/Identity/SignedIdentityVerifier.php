<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Identity;

use App\Models\Widget;
use App\Support\Secrets\VaultInterim;
use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;

/**
 * Doc 13 FR-07 / SEC-03 — the signed-identity check: HMAC-SHA256 over the
 * CANONICAL payload with the per-widget secret, compared in CONSTANT TIME,
 * replay bounded by the payload's own timestamp (±5 min). During a rotation's
 * overlap window BOTH the current and the previous secret verify (Doc 02
 * FR-19's pattern); after it, only the current one.
 *
 * Canonical form: the payload's keys sorted, JSON-encoded without escaping
 * slashes/unicode — the host page signs exactly this string (Install tab's
 * note). An invalid signature is NOT an error to the visitor (a 204 and a
 * Health counter): the site asserted, the platform declined to believe.
 */
final class SignedIdentityVerifier
{
    public const REPLAY_WINDOW_SECONDS = 300;

    public function __construct(
        private readonly VaultInterim $vault,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return string the reason when refused ('' when verified)
     */
    public function refusal(Widget $widget, array $payload, string $signature, CarbonImmutable $now): string
    {
        $ts = $payload['ts'] ?? null;
        if (! is_int($ts) && ! (is_string($ts) && ctype_digit($ts))) {
            return 'no_timestamp';
        }
        if (abs($now->getTimestamp() - (int) $ts) > self::REPLAY_WINDOW_SECONDS) {
            return 'replayed';
        }
        if (! is_string($payload['user_ref'] ?? null) || $payload['user_ref'] === '') {
            return 'no_user_ref';
        }
        if (! preg_match('/^[0-9a-f]{64}$/', $signature)) {
            return 'malformed_signature';
        }

        $secrets = [];
        foreach ([$widget->identity_secret_ref, $this->overlapRef($widget, $now)] as $ref) {
            if (is_string($ref)) {
                $secret = $this->vault->reveal($ref);
                if ($secret !== null) {
                    $secrets[] = $secret;
                }
            }
        }
        if ($secrets === []) {
            return 'no_secret';
        }

        $canonical = self::canonical($payload);
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $canonical, $secret), $signature)) {
                return '';
            }
        }

        return 'bad_signature';
    }

    /** @param array<string, mixed> $payload */
    public static function canonical(array $payload): string
    {
        ksort($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The previous secret is honoured only inside the overlap window after the rotation stamp. */
    private function overlapRef(Widget $widget, CarbonImmutable $now): ?string
    {
        $previous = $widget->identity_secret_previous_ref;
        $rotatedAt = $widget->identity_secret_rotated_at;
        if (! is_string($previous) || $rotatedAt === null) {
            return null;
        }
        $hours = $this->settings->get('widget.identity.rotation_overlap_hours'); // a registry SETTING (platform dial), never config
        $hours = is_numeric($hours) ? (int) $hours : 24;

        return $now->lessThan($rotatedAt->addHours($hours)) ? $previous : null;
    }
}
