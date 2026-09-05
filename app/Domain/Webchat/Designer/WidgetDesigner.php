<?php

declare(strict_types=1);

namespace App\Domain\Webchat\Designer;

use App\Domain\Auth\Widget\OriginAllowlist;
use App\Domain\Webchat\Identity\WidgetSecretMinter;
use App\Domain\Webchat\Visitor\VisitorConversation;
use App\Models\Widget;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantKeyIssuer;
use Illuminate\Support\Facades\DB;

/**
 * Doc 13 FR-19 (S13 step 10): the widgets themselves — CRUD WITHOUT DELETE (a widget is
 * retired by `deleted_at`, C3: its shim answers an empty 404, its rows stay), key minting
 * through the ONE issuer (the plaintext is shown once and never stored), the allowlist
 * editor (every entry validated by the Doc 02 matcher before it is saved — EC-12's
 * boundary is the matcher's, not a second parser here), the signed-identity secret
 * through the ONE minter (SEC-03 overlap), test mode (BR-07 / P-N14) and the bundle
 * channel. Every mutation audits under its registered key.
 */
final class WidgetDesigner
{
    public function __construct(
        private readonly TenantKeyIssuer $keys,
        private readonly OriginAllowlist $origins,
        private readonly WidgetSecretMinter $secrets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $allowedOrigins
     * @return array{widget: Widget, key_plaintext: string}
     */
    public function create(string $name, array $allowedOrigins, string $actorId): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new ConfigRefused('invalid_name', 'a widget needs a name of 1–80 characters');
        }
        $origins = $this->normalizedOrigins($allowedOrigins);

        return DB::transaction(function () use ($name, $origins, $actorId): array {
            $issued = $this->keys->issue(kind: 'widget', label: $name, actor: AuditActor::Staff, actorId: $actorId);
            /** @var \App\Models\TenantKey $key */
            $key = $issued['key'];
            $widget = Widget::query()->create(['key_id' => $key->getKey(), 'allowed_origins' => $origins, 'name' => $name]);
            $widgetId = VisitorConversation::widgetId($widget);
            $this->audit->record('widget.created', 'widget', $widgetId, AuditActor::Staff, ['widget_id' => $widgetId, 'name' => $name, 'allowed_origins' => $origins], actorId: $actorId);

            return ['widget' => $widget, 'key_plaintext' => (string) $issued['plaintext']];
        });
    }

    /**
     * @param  array{name?: string, bundle_channel?: string, test_mode?: bool}  $changes
     * @return array{widget: Widget, changed: list<string>}
     */
    public function update(Widget $widget, array $changes, string $actorId): array
    {
        $widgetId = VisitorConversation::widgetId($widget);
        /** @var list<string> $changed */
        $changed = DB::transaction(function () use ($widget, $widgetId, $changes, $actorId): array {
            $changed = [];
            if (array_key_exists('name', $changes)) {
                $name = trim($changes['name']);
                if ($name === '' || mb_strlen($name) > 80) {
                    throw new ConfigRefused('invalid_name', 'a widget needs a name of 1–80 characters');
                }
                if ($name !== $widget->name) {
                    $widget->name = $name;
                    $changed[] = 'name';
                }
            }
            if (array_key_exists('bundle_channel', $changes)) {
                $channel = $changes['bundle_channel'];
                if (! in_array($channel, ['stable', 'early'], true)) {
                    throw new ConfigRefused('invalid_channel', 'the bundle channel is stable or early');
                }
                if ($channel !== $widget->bundle_channel) {
                    $old = (string) $widget->bundle_channel;
                    $widget->bundle_channel = $channel;
                    $changed[] = 'bundle_channel';
                    $this->audit->record('widget.bundle_channel.changed', 'widget', $widgetId, AuditActor::Staff, ['widget_id' => $widgetId, 'old' => $old, 'new' => $channel], actorId: $actorId);
                }
            }
            if (array_key_exists('test_mode', $changes)) {
                $mode = (bool) $changes['test_mode'];
                if ($mode !== (bool) $widget->test_mode) {
                    $widget->test_mode = $mode;
                    $changed[] = 'test_mode';
                    $this->audit->record('widget.test_mode.changed', 'widget', $widgetId, AuditActor::Staff, ['widget_id' => $widgetId, 'test_mode' => $mode], actorId: $actorId);
                }
            }
            if ($widget->isDirty()) {
                $widget->save();
            }
            if ($changed !== []) {
                $this->audit->record('widget.updated', 'widget', $widgetId, AuditActor::Staff, ['widget_id' => $widgetId, 'changed' => $changed], actorId: $actorId);
            }

            return $changed;
        });

        return ['widget' => $widget->refresh(), 'changed' => $changed];
    }

    /** C3: retirement is a timestamp, never a DELETE — the shim answers an empty 404 from then on; rows and audit stay. */
    public function retire(Widget $widget, string $actorId): Widget
    {
        $widgetId = VisitorConversation::widgetId($widget);
        DB::transaction(function () use ($widget, $widgetId, $actorId): void {
            if ($widget->deleted_at !== null) {
                return;
            }
            DB::table('widgets')->where('id', $widgetId)->update(['deleted_at' => DB::raw('clock_timestamp()')]); // DB time (C-11); the cast cannot carry an expression
            $this->audit->record('widget.updated', 'widget', $widgetId, AuditActor::Staff, ['widget_id' => $widgetId, 'changed' => ['deleted_at']], actorId: $actorId);
        });

        return $widget->refresh();
    }

    /**
     * @param  list<string>  $entries
     * @return array{added: list<string>, removed: list<string>, allowed_origins: list<string>}
     */
    public function setAllowedOrigins(Widget $widget, array $entries, string $actorId): array
    {
        $next = $this->normalizedOrigins($entries);
        /** @var list<string> $current */
        $current = $widget->allowed_origins ?? [];
        $added = array_values(array_diff($next, $current));
        $removed = array_values(array_diff($current, $next));
        $widgetId = VisitorConversation::widgetId($widget);
        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => [], 'allowed_origins' => $current];
        }
        DB::transaction(function () use ($widget, $widgetId, $next, $added, $removed, $actorId): void {
            $widget->forceFill(['allowed_origins' => $next])->save();
            $this->audit->record('widget.allowlist.changed', 'widget', $widgetId, AuditActor::Staff, ['widget_id' => $widgetId, 'added' => $added, 'removed' => $removed], actorId: $actorId);
        });

        return ['added' => $added, 'removed' => $removed, 'allowed_origins' => $next];
    }

    /** SEC-03: the first secret, through the ONE minter; the plaintext is returned once and never stored. */
    public function createSecret(Widget $widget, string $actorId): string
    {
        if (is_string($widget->identity_secret_ref)) {
            throw new ConfigRefused('secret_exists', 'this widget already has a secret — rotate it instead');
        }

        return $this->secrets->mint($widget, $actorId);
    }

    /** SEC-03: rotation keeps the previous secret valid for the overlap window (Doc 02 FR-19's pattern). */
    public function rotateSecret(Widget $widget, string $actorId): string
    {
        if (! is_string($widget->identity_secret_ref)) {
            throw new ConfigRefused('no_secret', 'this widget has no secret yet — create one first');
        }

        return $this->secrets->mint($widget, $actorId);
    }

    /**
     * Every entry must be an origin the Doc 02 matcher can match: `https://host[:port]` exactly, or `*.host` for
     * subdomains (EC-12: the dot boundary is the matcher's). Anything else is refused by name.
     *
     * @param  list<mixed>  $entries
     * @return list<string>
     */
    private function normalizedOrigins(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $e = strtolower(trim(is_string($entry) ? $entry : ''));
            if ($e === '') {
                continue;
            }
            if (str_starts_with($e, '*.')) {
                $probe = 'https://sub.'.substr($e, 2);
                if (! $this->origins->matches($probe, [$e]) || $this->origins->matches('https://evil-'.substr($e, 2), [$e])) {
                    throw new ConfigRefused('invalid_origin', 'not a valid wildcard domain: '.$e, ['entry' => $e]);
                }
            } elseif (! str_starts_with($e, 'https://') || ! $this->origins->matches($e, [$e])) {
                throw new ConfigRefused('invalid_origin', 'not a valid https origin: '.$e, ['entry' => $e]);
            }
            $out[$e] = $e;
        }
        if (count($out) > 50) {
            throw new ConfigRefused('too_many_origins', 'at most 50 allowed domains');
        }

        return array_values($out);
    }
}
