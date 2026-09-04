<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Identity;

use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Models\Widget;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Secrets\VaultInterim;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 SEC-03 / FR-19 Install — the signed-identity secret's lifecycle:
 * minted ONCE (the plaintext is returned to the caller exactly once and
 * exists nowhere else — the vault interim holds ciphertext, the widget row
 * holds the reference), rotated with an OVERLAP window (Doc 02 FR-19's
 * pattern: the predecessor keeps verifying until `identity_secret_rotated_at`
 * + `widget.identity.rotation_overlap_hours`, then is retired — never
 * deleted). The rotation stamp is the DATABASE's (SCHEMA AMENDMENT 7 C1: the
 * trigger fires when the ref changes; the app never writes the column).
 * Audit rows never carry a value or a ref.
 */
final class WidgetSecretMinter
{
    public function __construct(
        private readonly VaultInterim $vault,
        private readonly AuditLogger $audit,
        private readonly SettingsRepository $settings,
    ) {}

    /** @return string the plaintext, shown ONCE */
    public function mint(Widget $widget, string $actorId): string
    {
        $plaintext = 'wis_'.bin2hex(random_bytes(32));
        $ref = $this->nextRef($widget);

        DB::transaction(function () use ($widget, $ref, $plaintext, $actorId): void {
            $this->vault->put($ref, $plaintext);
            $previous = $widget->identity_secret_ref;
            $widget->forceFill([
                'identity_secret_ref' => $ref,
                'identity_secret_previous_ref' => is_string($previous) ? $previous : null,
            ])->save();
            $this->audit->record(
                $previous === null ? 'widget.secret.created' : 'widget.secret.rotated',
                'widget',
                VisitorConversation::widgetId($widget),
                AuditActor::Staff,
                $previous === null
                    ? ['widget_id' => VisitorConversation::widgetId($widget)]
                    : ['widget_id' => VisitorConversation::widgetId($widget), 'overlap_until' => $this->overlapUntil($widget->fresh())],
                actorId: $actorId,
            );
        });

        return $plaintext;
    }

    /** Close an expired overlap window: retire the predecessor's material (the row stays). */
    public function sweepOverlap(Widget $widget, \DateTimeImmutable $now): void
    {
        $previous = $widget->identity_secret_previous_ref;
        $rotatedAt = $widget->identity_secret_rotated_at;
        if (! is_string($previous) || $rotatedAt === null) {
            return;
        }
        if ($now >= $rotatedAt->addHours($this->overlapHours())) {
            $this->vault->retire($previous);
            $widget->forceFill(['identity_secret_previous_ref' => null])->save();
        }
    }

    public function overlapHours(): int
    {
        $hours = $this->settings->get('widget.identity.rotation_overlap_hours');

        return is_numeric($hours) ? (int) $hours : 24;
    }

    private function overlapUntil(?Widget $widget): ?string
    {
        $rotatedAt = $widget?->identity_secret_rotated_at;

        return $rotatedAt?->addHours($this->overlapHours())->toIso8601String();
    }

    private function nextRef(Widget $widget): string
    {
        $current = $widget->identity_secret_ref;
        $n = 1;
        if (is_string($current) && preg_match('/\/v(\d+)$/', $current, $m) === 1) {
            $n = (int) $m[1] + 1;
        }

        return sprintf('vault://widget/%s/v%d', VisitorConversation::widgetId($widget), $n);
    }
}
